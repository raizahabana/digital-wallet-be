<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['name' => 'Test User', 'email' => 'test@example.com', 'balance' => '1000.00'],
            ['name' => 'Second User', 'email' => 'second@example.com', 'balance' => '250.00'],
        ];

        foreach ($users as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                ['name' => $data['name'], 'password' => 'password'] // 'hashed' cast hashes it
            );

            // Only a brand-new user gets an opening balance. The wallet itself was created
            // automatically with the user, so re-running the seeder never adds money twice.
            if ($user->wasRecentlyCreated) {
                $wallet = $user->wallet()->firstOrFail();
                $wallet->update(['balance' => $data['balance'], 'currency' => 'USD']);

                // Every unit of balance needs a ledger entry, otherwise the ledger cannot be reconciled.
                $wallet->transactions()->create([
                    'type' => 'deposit',
                    'amount' => $data['balance'],
                    'metadata' => ['note' => 'Opening balance'],
                ]);
            }
        }
    }
}
