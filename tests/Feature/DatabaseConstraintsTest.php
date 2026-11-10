<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

/**
 * The application validates input, but the schema is the last line of defence. These tests
 * bypass the application layer to prove the database itself refuses bad data.
 */
class DatabaseConstraintsTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    private function requireCheckConstraints(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('The migrations only add CHECK constraints on MySQL/MariaDB/PostgreSQL.');
        }
    }

    public function test_the_database_rejects_a_negative_wallet_balance_on_insert(): void
    {
        $this->requireCheckConstraints();

        $this->expectException(QueryException::class);

        User::factory()->create()->wallet()->update(['balance' => '-0.01']);
    }

    public function test_the_database_rejects_an_update_that_would_overdraw_a_wallet(): void
    {
        $this->requireCheckConstraints();
        $user = $this->userWithWallet('10.00');

        try {
            $user->wallet->decrement('balance', '10.01');
            $this->fail('Expected the CHECK constraint to reject a negative balance.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame('10.00', $this->balanceOf($user));
    }

    /** @return array<string, array{string}> */
    public static function nonPositiveAmounts(): array
    {
        return ['zero' => ['0.00'], 'negative' => ['-5.00']];
    }

    #[DataProvider('nonPositiveAmounts')]
    public function test_the_database_rejects_a_non_positive_ledger_amount(string $amount): void
    {
        $this->requireCheckConstraints();
        $user = $this->userWithWallet();

        $this->expectException(QueryException::class);

        $user->wallet->transactions()->create(['type' => 'deposit', 'amount' => $amount]);
    }

    public function test_the_database_rejects_an_unknown_transaction_type(): void
    {
        $user = $this->userWithWallet();

        $this->expectException(QueryException::class);

        $user->wallet->transactions()->create(['type' => 'refund', 'amount' => '5.00']);
    }

    public function test_a_user_can_only_have_one_wallet(): void
    {
        $user = $this->userWithWallet();

        $this->expectException(QueryException::class);

        Wallet::factory()->for($user)->create();
    }

    public function test_deleting_a_user_cascades_to_their_wallet_and_ledger(): void
    {
        $user = $this->userWithWallet();
        $user->wallet->transactions()->create(['type' => 'deposit', 'amount' => '5.00']);

        $user->delete();

        $this->assertDatabaseCount('wallets', 0);
        $this->assertSame(0, Transaction::count());
    }

    public function test_the_ledger_has_a_wallet_and_date_index_for_statement_queries(): void
    {
        $indexes = collect(\Illuminate\Support\Facades\Schema::getIndexes('transactions'));

        $this->assertTrue(
            $indexes->contains(fn ($index) => $index['columns'] === ['wallet_id', 'created_at']),
            'transactions needs an index on [wallet_id, created_at].',
        );
    }
}
