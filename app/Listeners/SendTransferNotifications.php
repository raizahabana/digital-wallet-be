<?php

namespace App\Listeners;

use App\Events\TransferCompleted;
use App\Models\Wallet;
use App\Notifications\TransferNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Runs on the queue worker, so notification I/O never slows the transfer response. */
class SendTransferNotifications implements ShouldQueue
{
    public int $tries = 3;

    public function handle(TransferCompleted $event): void
    {
        $wallets = Wallet::with('user')
            ->whereIn('id', [$event->senderWalletId, $event->recipientWalletId])
            ->get()
            ->keyBy('id');

        $sender = $wallets->get($event->senderWalletId)?->user;
        $recipient = $wallets->get($event->recipientWalletId)?->user;

        $sender?->notify(new TransferNotification(
            'sent', $event->amount, $event->currency, $recipient?->email ?? 'unknown', $event->referenceId,
        ));

        $recipient?->notify(new TransferNotification(
            'received', $event->amount, $event->currency, $sender?->email ?? 'unknown', $event->referenceId,
        ));
    }
}
