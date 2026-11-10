<?php

namespace Tests\Unit\Actions;

use App\Actions\Wallet\DepositFunds;
use App\DataTransferObjects\DepositData;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

class DepositFundsTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    public function test_it_increments_the_balance_and_returns_the_ledger_entry(): void
    {
        $user = $this->userWithWallet('10.00');

        $transaction = app(DepositFunds::class)->execute(new DepositData($user, '2.50'));

        $this->assertInstanceOf(Transaction::class, $transaction);
        $this->assertSame('deposit', $transaction->type);
        $this->assertSame('2.50', $transaction->amount);
        $this->assertNotNull($transaction->created_at);
        $this->assertSame('12.50', $this->balanceOf($user));
    }

    public function test_every_deposit_writes_its_own_ledger_row(): void
    {
        $user = $this->userWithWallet('0.00');
        $action = app(DepositFunds::class);

        $action->execute(new DepositData($user, '1.00'));
        $action->execute(new DepositData($user, '1.00'));

        $this->assertSame('2.00', $this->balanceOf($user));
        $this->assertSame(2, Transaction::count());
    }

    public function test_it_fails_when_the_user_has_no_wallet(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $user = User::factory()->create();
        $user->wallet()->delete();

        app(DepositFunds::class)->execute(new DepositData($user, '1.00'));
    }
}
