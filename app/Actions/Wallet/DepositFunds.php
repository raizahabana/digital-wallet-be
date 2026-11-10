<?php

namespace App\Actions\Wallet;

use App\DataTransferObjects\DepositData;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class DepositFunds
{
    public function execute(DepositData $data): Transaction
    {
        return DB::transaction(function () use ($data) {
            $wallet = Wallet::where('user_id', $data->user->id)->lockForUpdate()->firstOrFail();

            $wallet->increment('balance', $data->amount);

            return $wallet->transactions()
                ->create(['type' => 'deposit', 'amount' => $data->amount])
                ->refresh(); // loads the DB-default created_at
        });
    }
}
