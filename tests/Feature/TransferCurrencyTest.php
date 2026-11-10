<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

class TransferCurrencyTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    private function walletIn(string $currency, string $balance): User
    {
        $user = User::factory()->create();
        $user->wallet()->update(['balance' => $balance, 'currency' => $currency]);

        return $user;
    }

    public function test_it_rejects_a_transfer_between_different_currencies_and_changes_nothing(): void
    {
        $sender = $this->walletIn('USD', '100.00');
        $recipient = $this->walletIn('JPY', '0.00');
        Sanctum::actingAs($sender);

        $this->postJson('/api/v1/wallet/transfer',
            ['recipient_email' => $recipient->email, 'amount' => '10.00'],
            ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('title', 'Currency mismatch');

        $this->assertSame('100.00', $this->balanceOf($sender));
        $this->assertSame('0.00', $this->balanceOf($recipient));
        $this->assertSame(0, Transaction::count());
    }

    public function test_it_still_allows_transfers_within_the_same_non_default_currency(): void
    {
        $sender = $this->walletIn('EUR', '100.00');
        $recipient = $this->walletIn('EUR', '0.00');
        Sanctum::actingAs($sender);

        $this->postJson('/api/v1/wallet/transfer',
            ['recipient_email' => $recipient->email, 'amount' => '10.00'],
            ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(201);

        $this->assertSame('90.00', $this->balanceOf($sender));
        $this->assertSame('10.00', $this->balanceOf($recipient));
    }
}
