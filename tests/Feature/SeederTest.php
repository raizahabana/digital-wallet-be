<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_seeded_wallet_balance_is_backed_by_the_ledger(): void
    {
        $this->seed();

        $this->assertSame(2, Wallet::count());

        foreach (Wallet::all() as $wallet) {
            $ledger = Transaction::where('wallet_id', $wallet->id)->sum('amount');

            $this->assertEquals($wallet->balance, $ledger, "Wallet {$wallet->id} does not reconcile.");
        }
    }

    public function test_seeding_twice_does_not_duplicate_ledger_entries(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(2, Wallet::count());
        $this->assertSame(2, Transaction::count());
    }
}
