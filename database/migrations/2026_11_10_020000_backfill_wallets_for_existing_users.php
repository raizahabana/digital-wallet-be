<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Users created before wallets were auto-provisioned (tinker, factories, manual inserts) have no
 * wallet and get "Not Found" from every wallet endpoint. Give each of them an empty one.
 */
return new class extends Migration
{
    public function up(): void
    {
        User::doesntHave('wallet')->each(function (User $user) {
            $user->wallet()->create(['balance' => '0.00', 'currency' => 'USD']);
        });
    }

    public function down(): void
    {
        // Intentionally empty: removing wallets would destroy balances.
    }
};
