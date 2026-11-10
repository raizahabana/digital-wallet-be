<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

class TransferTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    private function transfer(array $payload, ?string $key = null)
    {
        return $this->postJson('/api/v1/wallet/transfer', $payload, [
            'Idempotency-Key' => $key ?? (string) Str::uuid(),
        ]);
    }

    public function test_it_moves_funds_and_writes_two_linked_ledger_entries(): void
    {
        $sender = $this->userWithWallet('100.00');
        $recipient = $this->userWithWallet('20.00');
        Sanctum::actingAs($sender);

        $response = $this->transfer(['recipient_email' => $recipient->email, 'amount' => '30.25'])
            ->assertStatus(201);

        $this->assertSame('69.75', $this->balanceOf($sender));
        $this->assertSame('50.25', $this->balanceOf($recipient));

        $reference = $response->json('data.reference_id');
        $this->assertTrue(Str::isUuid($reference));

        $entries = Transaction::where('reference_id', $reference)->get();
        $this->assertCount(2, $entries);
        $this->assertEqualsCanonicalizing(['transfer_out', 'transfer_in'], $entries->pluck('type')->all());
        $this->assertSame($sender->wallet->id, $entries->firstWhere('type', 'transfer_out')->wallet_id);
        $this->assertSame($recipient->wallet->id, $entries->firstWhere('type', 'transfer_in')->wallet_id);
    }

    public function test_it_allows_transferring_the_exact_balance(): void
    {
        $sender = $this->userWithWallet('10.00');
        $recipient = $this->userWithWallet('0.00');
        Sanctum::actingAs($sender);

        $this->transfer(['recipient_email' => $recipient->email, 'amount' => '10.00'])->assertStatus(201);

        $this->assertSame('0.00', $this->balanceOf($sender));
        $this->assertSame('10.00', $this->balanceOf($recipient));
    }

    public function test_it_rejects_an_overdraft_and_changes_nothing(): void
    {
        $sender = $this->userWithWallet('10.00');
        $recipient = $this->userWithWallet('0.00');
        Sanctum::actingAs($sender);

        $this->transfer(['recipient_email' => $recipient->email, 'amount' => '10.01'])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('title', 'Insufficient funds');

        $this->assertSame('10.00', $this->balanceOf($sender));
        $this->assertSame('0.00', $this->balanceOf($recipient));
        $this->assertSame(0, Transaction::count());
    }

    public function test_a_second_transfer_cannot_spend_money_the_first_already_used(): void
    {
        // Sequential stand-in for a double-spend: the wallet holds 100, two transfers want 60 each.
        $sender = $this->userWithWallet('100.00');
        $recipient = $this->userWithWallet('0.00');
        Sanctum::actingAs($sender);

        $payload = ['recipient_email' => $recipient->email, 'amount' => '60.00'];

        $this->transfer($payload)->assertStatus(201);
        $this->transfer($payload)->assertStatus(422);

        $this->assertSame('40.00', $this->balanceOf($sender));
        $this->assertSame('60.00', $this->balanceOf($recipient));
        $this->assertSame(2, Transaction::count());
    }

    public function test_it_locks_wallet_rows_before_checking_the_balance(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite ignores FOR UPDATE. Run the tests against MySQL or PostgreSQL.');
        }

        $sender = $this->userWithWallet('100.00');
        $recipient = $this->userWithWallet('0.00');
        Sanctum::actingAs($sender);

        DB::enableQueryLog();
        $this->transfer(['recipient_email' => $recipient->email, 'amount' => '10.00'])->assertStatus(201);

        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => strtolower($q))->values();

        $lockAt = $queries->search(fn ($q) => str_contains($q, 'for update'));
        $firstUpdateAt = $queries->search(fn ($q) => str_starts_with($q, 'update `wallets`') || str_starts_with($q, 'update "wallets"'));

        $this->assertNotFalse($lockAt, 'Expected a SELECT ... FOR UPDATE on wallets.');
        $this->assertNotFalse($firstUpdateAt, 'Expected a balance UPDATE on wallets.');
        $this->assertLessThan($firstUpdateAt, $lockAt, 'The lock must be taken before any balance is changed.');
    }

    public function test_a_failure_while_writing_the_ledger_rolls_everything_back(): void
    {
        $sender = $this->userWithWallet('100.00');
        $recipient = $this->userWithWallet('0.00');
        Sanctum::actingAs($sender);

        // Make the SECOND ledger write blow up, after the balances were already changed.
        Transaction::creating(function (Transaction $transaction) {
            if ($transaction->type === 'transfer_in') {
                throw new \RuntimeException('simulated database failure');
            }
        });

        $this->transfer(['recipient_email' => $recipient->email, 'amount' => '10.00'])
            ->assertStatus(500)
            ->assertJsonPath('detail', 'An unexpected error occurred.'); // no internals leaked

        $this->assertSame('100.00', $this->balanceOf($sender));
        $this->assertSame('0.00', $this->balanceOf($recipient));
        $this->assertSame(0, Transaction::count());
    }

    public function test_it_rejects_a_transfer_to_yourself(): void
    {
        $sender = $this->userWithWallet('100.00');
        Sanctum::actingAs($sender);

        $this->transfer(['recipient_email' => $sender->email, 'amount' => '10.00'])
            ->assertStatus(422)
            ->assertJsonPath('title', 'Invalid transfer');

        $this->assertSame('100.00', $this->balanceOf($sender));
    }

    public function test_it_returns_404_for_an_unknown_recipient(): void
    {
        Sanctum::actingAs($this->userWithWallet('100.00'));

        $this->transfer(['recipient_email' => 'nobody@example.com', 'amount' => '10.00'])
            ->assertStatus(404)
            ->assertJsonPath('title', 'Recipient not found');
    }

    public function test_it_returns_404_for_a_recipient_without_a_wallet(): void
    {
        $noWallet = User::factory()->create();
        $noWallet->wallet()->delete();
        Sanctum::actingAs($this->userWithWallet('100.00'));

        $this->transfer(['recipient_email' => $noWallet->email, 'amount' => '10.00'])->assertStatus(404);
    }

    #[DataProvider('invalidAmounts')]
    public function test_it_rejects_invalid_amounts(mixed $amount): void
    {
        $sender = $this->userWithWallet('100.00');
        $recipient = $this->userWithWallet('0.00');
        Sanctum::actingAs($sender);

        $this->transfer(['recipient_email' => $recipient->email, 'amount' => $amount])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertSame('100.00', $this->balanceOf($sender));
        $this->assertSame(0, Transaction::count());
    }

    public static function invalidAmounts(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-1'],
            'text' => ['abc'],
            'three decimals' => ['1.001'],
            'missing' => [null],
        ];
    }

    public function test_it_requires_a_valid_recipient_email(): void
    {
        Sanctum::actingAs($this->userWithWallet('100.00'));

        $this->transfer(['recipient_email' => 'not-an-email', 'amount' => '10.00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recipient_email');
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/v1/wallet/transfer', ['recipient_email' => 'a@b.com', 'amount' => '1.00'])
            ->assertStatus(401);
    }
}