<?php

namespace App\Actions\Wallet;

use App\DataTransferObjects\TransferData;
use App\DataTransferObjects\TransferResult;
use App\Events\TransferCompleted;
use App\Exceptions\CurrencyMismatchException;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\RecipientNotFoundException;
use App\Exceptions\SelfTransferException;
use App\Models\User;
use App\Models\Wallet;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransferFunds
{
    public function execute(TransferData $data): TransferResult
    {
        $sender = $data->sender;
        $amount = $data->amount;

        $recipient = User::where('email', $data->recipientEmail)->first();
        $recipientWalletId = $recipient?->wallet()->value('id');

        if (! $recipientWalletId) {
            throw new RecipientNotFoundException();
        }

        if ($recipient->is($sender)) {
            throw new SelfTransferException();
        }

        $senderWalletId = $sender->wallet()->firstOrFail()->id;
        $reference = (string) Str::uuid();

        DB::transaction(function () use ($senderWalletId, $recipientWalletId, $amount, $reference) {
            // Lock both rows in a fixed (ID) order so opposite transfers cannot deadlock.
            $wallets = Wallet::whereIn('id', [$senderWalletId, $recipientWalletId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $from = $wallets[$senderWalletId];
            $to = $wallets[$recipientWalletId];

            // Money must not change currency in transit (no exchange rate is applied).
            if ($from->currency !== $to->currency) {
                throw new CurrencyMismatchException();
            }

            // Balance is read AFTER the lock is held.
            if (BigDecimal::of($from->balance)->isLessThan($amount)) {
                throw new InsufficientFundsException();
            }

            $from->decrement('balance', $amount);
            $to->increment('balance', $amount);

            $from->transactions()->create([
                'type' => 'transfer_out',
                'amount' => $amount,
                'reference_id' => $reference,
                'metadata' => ['counterparty_wallet_id' => $to->id],
            ]);

            $to->transactions()->create([
                'type' => 'transfer_in',
                'amount' => $amount,
                'reference_id' => $reference,
                'metadata' => ['counterparty_wallet_id' => $from->id],
            ]);

            // Deferred until COMMIT and discarded on rollback (see TransferCompleted).
            TransferCompleted::dispatch($reference, $from->id, $to->id, $amount, $from->currency);
        });

        return new TransferResult($reference, $data->recipientEmail, $amount);
    }
}