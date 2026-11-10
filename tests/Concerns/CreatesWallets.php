<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Models\Wallet;

trait CreatesWallets
{
    protected function userWithWallet(string $balance = '100.00', ?string $email = null): User
    {
        // The wallet is created automatically with the user; just set its starting balance.
        $user = User::factory()->create($email ? ['email' => $email] : []);
        $user->wallet()->update(['balance' => $balance]);

        return $user;
    }

    protected function balanceOf(User $user): string
    {
        return $user->wallet()->first()->balance;
    }
}