<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Receipt for one side of a transfer. $direction is "sent" or "received". */
class TransferNotification extends Notification
{
    public function __construct(
        public string $direction,
        public string $amount,
        public string $currency,
        public string $counterpartyEmail,
        public string $referenceId,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $sent = $this->direction === 'sent';

        return (new MailMessage)
            ->subject($sent ? 'You sent a transfer' : 'You received a transfer')
            ->line(sprintf(
                'You %s %s %s %s %s.',
                $sent ? 'sent' : 'received',
                $this->amount,
                $this->currency,
                $sent ? 'to' : 'from',
                $this->counterpartyEmail,
            ))
            ->line("Reference: {$this->referenceId}");
    }
}
