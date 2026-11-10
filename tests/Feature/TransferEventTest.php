<?php

namespace Tests\Feature;

use App\Events\TransferCompleted;
use App\Listeners\SendTransferNotifications;
use App\Notifications\TransferNotification;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

class TransferEventTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    private function transfer(array $payload)
    {
        return $this->postJson('/api/v1/wallet/transfer', $payload, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_a_completed_transfer_dispatches_one_event_with_the_ledger_reference(): void
    {
        Notification::fake();
        $captured = [];
        Event::listen(TransferCompleted::class, function (TransferCompleted $event) use (&$captured) {
            $captured[] = $event;
        });

        $sender = $this->userWithWallet('100.00');
        $recipient = $this->userWithWallet('0.00');
        Sanctum::actingAs($sender);

        $reference = $this->transfer(['recipient_email' => $recipient->email, 'amount' => '30.25'])
            ->assertStatus(201)
            ->json('data.reference_id');

        $this->assertCount(1, $captured);
        $this->assertSame($reference, $captured[0]->referenceId);
        $this->assertSame($sender->wallet->id, $captured[0]->senderWalletId);
        $this->assertSame($recipient->wallet->id, $captured[0]->recipientWalletId);
        $this->assertSame('30.25', $captured[0]->amount);
        $this->assertSame('USD', $captured[0]->currency);
    }

    public function test_the_listener_runs_on_the_queue_not_inline(): void
    {
        Queue::fake();
        $sender = $this->userWithWallet('100.00');
        $recipient = $this->userWithWallet('0.00');
        Sanctum::actingAs($sender);

        $this->transfer(['recipient_email' => $recipient->email, 'amount' => '10.00'])->assertStatus(201);

        Queue::assertPushed(
            CallQueuedListener::class,
            fn (CallQueuedListener $job) => $job->class === SendTransferNotifications::class,
        );
    }

    public function test_both_parties_are_notified_once_the_worker_runs_the_listener(): void
    {
        Notification::fake(); // QUEUE_CONNECTION=sync in phpunit.xml, so the queued listener runs now
        $sender = $this->userWithWallet('100.00');
        $recipient = $this->userWithWallet('0.00');
        Sanctum::actingAs($sender);

        $reference = $this->transfer(['recipient_email' => $recipient->email, 'amount' => '30.00'])
            ->json('data.reference_id');

        Notification::assertSentTo($sender, TransferNotification::class, fn (TransferNotification $n) =>
            $n->direction === 'sent'
            && $n->amount === '30.00'
            && $n->counterpartyEmail === $recipient->email
            && $n->referenceId === $reference);

        Notification::assertSentTo($recipient, TransferNotification::class, fn (TransferNotification $n) =>
            $n->direction === 'received' && $n->counterpartyEmail === $sender->email);
    }

    public function test_the_notification_mail_describes_the_transfer(): void
    {
        $user = $this->userWithWallet();
        $mail = (new TransferNotification('sent', '30.00', 'USD', 'bob@example.com', 'ref-1'))->toMail($user);

        $this->assertSame('You sent a transfer', $mail->subject);
        $this->assertStringContainsString('30.00 USD to bob@example.com', $mail->introLines[0]);
        $this->assertStringContainsString('ref-1', $mail->introLines[1]);
    }

    public function test_no_event_is_dispatched_for_a_rejected_transfer(): void
    {
        Queue::fake();
        Notification::fake();
        $sender = $this->userWithWallet('10.00');
        $recipient = $this->userWithWallet('0.00');
        Sanctum::actingAs($sender);

        $this->transfer(['recipient_email' => $recipient->email, 'amount' => '999.00'])->assertStatus(422);

        Queue::assertNothingPushed();
        Notification::assertNothingSent();
    }

    public function test_an_event_dispatched_inside_a_rolled_back_transaction_never_reaches_listeners(): void
    {
        Queue::fake();

        try {
            DB::transaction(function () {
                TransferCompleted::dispatch('ref', 1, 2, '10.00', 'USD');

                throw new \RuntimeException('something failed after the event was dispatched');
            });
        } catch (\RuntimeException) {
        }

        Queue::assertNothingPushed();
    }

    public function test_an_event_dispatched_inside_a_committed_transaction_reaches_listeners(): void
    {
        Queue::fake();

        DB::transaction(fn () => TransferCompleted::dispatch('ref', 1, 2, '10.00', 'USD'));

        Queue::assertPushed(CallQueuedListener::class, 1);
    }
}
