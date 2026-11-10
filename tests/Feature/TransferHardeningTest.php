<?php

namespace Tests\Feature;

use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

/**
 * Optional senior modules: Idempotency-Key and the 5-per-minute throttle.
 */
class TransferHardeningTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    public function test_the_idempotency_key_header_is_required(): void
    {
        $sender = $this->userWithWallet('100.00');
        $recipient = $this->userWithWallet('0.00');
        Sanctum::actingAs($sender);

        $this->postJson('/api/v1/wallet/transfer', ['recipient_email' => $recipient->email, 'amount' => '5.00'])
            ->assertStatus(422)
            ->assertJsonPath('title', 'Idempotency-Key required');

        $this->assertSame('100.00', $this->balanceOf($sender));
    }

    public function test_retrying_with_the_same_key_does_not_charge_twice(): void
    {
        $sender = $this->userWithWallet('100.00');
        $recipient = $this->userWithWallet('0.00');
        Sanctum::actingAs($sender);

        $payload = ['recipient_email' => $recipient->email, 'amount' => '25.00'];
        $headers = ['Idempotency-Key' => 'retry-me-123'];

        $first = $this->postJson('/api/v1/wallet/transfer', $payload, $headers)->assertStatus(201);
        $second = $this->postJson('/api/v1/wallet/transfer', $payload, $headers)
            ->assertStatus(201)
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json('data.reference_id'), $second->json('data.reference_id'));
        $this->assertSame('75.00', $this->balanceOf($sender));
        $this->assertSame('25.00', $this->balanceOf($recipient));
        $this->assertSame(2, Transaction::count());
    }

    public function test_reusing_a_key_with_a_different_payload_is_rejected(): void
    {
        $sender = $this->userWithWallet('100.00');
        $recipient = $this->userWithWallet('0.00');
        Sanctum::actingAs($sender);

        $headers = ['Idempotency-Key' => 'same-key'];

        $this->postJson('/api/v1/wallet/transfer', ['recipient_email' => $recipient->email, 'amount' => '10.00'], $headers)
            ->assertStatus(201);

        $this->postJson('/api/v1/wallet/transfer', ['recipient_email' => $recipient->email, 'amount' => '99.00'], $headers)
            ->assertStatus(422)
            ->assertJsonPath('title', 'Idempotency-Key reused');

        $this->assertSame('90.00', $this->balanceOf($sender));
    }

    public function test_a_failed_attempt_does_not_burn_the_key(): void
    {
        $sender = $this->userWithWallet('10.00');
        $recipient = $this->userWithWallet('0.00');
        Sanctum::actingAs($sender);

        $payload = ['recipient_email' => $recipient->email, 'amount' => '50.00'];
        $headers = ['Idempotency-Key' => 'fix-and-retry'];

        $this->postJson('/api/v1/wallet/transfer', $payload, $headers)->assertStatus(422);

        $sender->wallet()->update(['balance' => '100.00']); // user tops up, then retries

        $this->postJson('/api/v1/wallet/transfer', $payload, $headers)->assertStatus(201);
        $this->assertSame('50.00', $this->balanceOf($sender));
    }

    public function test_it_allows_five_transfer_attempts_per_minute_then_returns_429(): void
    {
        $sender = $this->userWithWallet('100.00');
        $recipient = $this->userWithWallet('0.00');
        Sanctum::actingAs($sender);

        $payload = ['recipient_email' => $recipient->email, 'amount' => '0.01'];

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/wallet/transfer', $payload, ['Idempotency-Key' => (string) Str::uuid()])
                ->assertStatus(201);
        }

        $response = $this->postJson('/api/v1/wallet/transfer', $payload, ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(429)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('title', 'Too many requests');

        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertSame('99.95', $this->balanceOf($sender));
    }

    public function test_the_limit_is_per_user(): void
    {
        $busy = $this->userWithWallet('100.00');
        $other = $this->userWithWallet('100.00');
        $recipient = $this->userWithWallet('0.00');

        Sanctum::actingAs($busy);
        foreach (range(1, 6) as $attempt) {
            $this->postJson('/api/v1/wallet/transfer',
                ['recipient_email' => $recipient->email, 'amount' => '0.01'],
                ['Idempotency-Key' => (string) Str::uuid()]);
        }

        Sanctum::actingAs($other);
        $this->postJson('/api/v1/wallet/transfer',
            ['recipient_email' => $recipient->email, 'amount' => '0.01'],
            ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(201);
    }
}