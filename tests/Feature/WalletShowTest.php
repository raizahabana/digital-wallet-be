<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

class WalletShowTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    public function test_it_returns_the_balance_and_currency(): void
    {
        Sanctum::actingAs($this->userWithWallet('123.45'));

        $this->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('data.balance', '123.45')
            ->assertJsonPath('data.currency', 'USD');
    }

    public function test_it_returns_only_the_latest_15_transactions_newest_first(): void
    {
        $user = $this->userWithWallet();
        $wallet = $user->wallet;

        foreach (range(1, 20) as $i) {
            $wallet->transactions()->create(['type' => 'deposit', 'amount' => '1.00']);
        }

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/wallet')->assertOk();
        $response->assertJsonCount(15, 'data.transactions');

        $ids = collect($response->json('data.transactions'))->pluck('id')->all();
        $sorted = $ids;
        rsort($sorted);
        $this->assertSame($sorted, $ids);
    }

    public function test_it_loads_transactions_with_a_single_query(): void
    {
        $user = $this->userWithWallet();
        foreach (range(1, 5) as $i) {
            $user->wallet->transactions()->create(['type' => 'deposit', 'amount' => '1.00']);
        }

        Sanctum::actingAs($user);

        DB::enableQueryLog();
        $this->getJson('/api/v1/wallet')->assertOk();

        $transactionQueries = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'transactions'))
            ->count();

        $this->assertSame(1, $transactionQueries, 'Transactions must be eager loaded, not queried per row.');
    }

    public function test_a_user_only_sees_their_own_wallet(): void
    {
        $mine = $this->userWithWallet('10.00');
        $this->userWithWallet('999.00');

        Sanctum::actingAs($mine);

        $this->getJson('/api/v1/wallet')->assertJsonPath('data.balance', '10.00');
    }
}