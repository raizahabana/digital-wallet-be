<?php

namespace Tests\Unit\Actions;

use App\Actions\Wallet\TransferFunds;
use App\DataTransferObjects\TransferData;
use App\Exceptions\CurrencyMismatchException;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\RecipientNotFoundException;
use App\Exceptions\SelfTransferException;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

class TransferFundsTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    private function run_(User $sender, string $email, string $amount)
    {
        return app(TransferFunds::class)->execute(new TransferData($sender, $email, $amount));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_it_returns_a_result_and_moves_the_money(): void
    {
        $sender = $this->userWithWallet('50.00');
        $recipient = $this->userWithWallet('5.00');

        $result = $this->run_($sender, $recipient->email, '12.50');

        $this->assertTrue(Str::isUuid($result->referenceId));
        $this->assertSame($recipient->email, $result->recipientEmail);
        $this->assertSame('12.50', $result->amount);
        $this->assertSame('37.50', $this->balanceOf($sender));
        $this->assertSame('17.50', $this->balanceOf($recipient));
        $this->assertSame(2, Transaction::where('reference_id', $result->referenceId)->count());
    }

    public function test_it_throws_insufficient_funds_and_leaves_everything_untouched(): void
    {
        $sender = $this->userWithWallet('10.00');
        $recipient = $this->userWithWallet('0.00');

        try {
            $this->run_($sender, $recipient->email, '10.01');
            $this->fail('Expected InsufficientFundsException.');
        } catch (InsufficientFundsException $e) {
            $this->assertSame(422, $e->status());
        }

        $this->assertSame('10.00', $this->balanceOf($sender));
        $this->assertSame('0.00', $this->balanceOf($recipient));
        $this->assertSame(0, Transaction::count());
    }

    public function test_it_refuses_to_transfer_to_yourself(): void
    {
        $sender = $this->userWithWallet('10.00');

        $this->expectException(SelfTransferException::class);

        $this->run_($sender, $sender->email, '1.00');
    }

    public function test_it_throws_for_an_unknown_recipient(): void
    {
        $sender = $this->userWithWallet('10.00');

        $this->expectException(RecipientNotFoundException::class);

        $this->run_($sender, 'nobody@example.com', '1.00');
    }

    public function test_it_throws_for_a_recipient_without_a_wallet(): void
    {
        $sender = $this->userWithWallet('10.00');
        $walletless = User::factory()->create();
        $walletless->wallet()->delete();

        $this->expectException(RecipientNotFoundException::class);

        $this->run_($sender, $walletless->email, '1.00');
    }

    public function test_it_refuses_a_cross_currency_transfer(): void
    {
        $sender = $this->userWithWallet('10.00');
        $recipient = User::factory()->create();
        $recipient->wallet()->update(['balance' => '0.00', 'currency' => 'EUR']);

        try {
            $this->run_($sender, $recipient->email, '1.00');
            $this->fail('Expected CurrencyMismatchException.');
        } catch (CurrencyMismatchException) {
            $this->assertSame('10.00', $this->balanceOf($sender));
            $this->assertSame('0.00', $this->balanceOf($recipient));
        }
    }
}
