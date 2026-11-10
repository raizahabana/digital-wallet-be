<?php

namespace Tests\Feature;

use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

class DepositTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    public function test_it_deposits_funds_and_writes_a_ledger_entry(): void
    {
        $user = $this->userWithWallet('100.00');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/wallet/deposit', ['amount' => '50.00'])
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'deposit')
            ->assertJsonPath('data.amount', '50.00');

        $this->assertDatabaseHas('wallets', ['id' => $user->wallet->id, 'balance' => '150.00']);
        $this->assertDatabaseHas('transactions', [
            'wallet_id' => $user->wallet->id,
            'type' => 'deposit',
            'amount' => '50.00',
        ]);
    }

    public function test_it_accepts_a_json_number_amount(): void
    {
        $user = $this->userWithWallet('100.00');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/wallet/deposit', ['amount' => 25.5])->assertStatus(201);

        $this->assertSame('125.50', $this->balanceOf($user));
    }

    #[DataProvider('invalidAmounts')]
    public function test_it_rejects_invalid_amounts(mixed $amount): void
    {
        $user = $this->userWithWallet('100.00');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/wallet/deposit', ['amount' => $amount])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonValidationErrors('amount');

        $this->assertSame('100.00', $this->balanceOf($user));
        $this->assertSame(0, Transaction::count());
    }

    public static function invalidAmounts(): array
    {
        return [
            'zero' => ['0'],
            'zero with decimals' => ['0.00'],
            'negative' => ['-5'],
            'text' => ['abc'],
            'three decimals' => ['10.999'],
            'empty' => [''],
            'scientific notation' => ['1e3'],
            'null' => [null],
        ];
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/v1/wallet/deposit', ['amount' => '10.00'])->assertStatus(401);
    }
}