<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a transfer has been written to the ledger.
 *
 * ShouldDispatchAfterCommit makes this transaction-safe: when it is dispatched inside DB::transaction(),
 * listeners only run once the transaction commits, and never run if it rolls back. Nobody is
 * notified about money that did not move.
 */
class TransferCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $referenceId,
        public readonly int $senderWalletId,
        public readonly int $recipientWalletId,
        public readonly string $amount,
        public readonly string $currency,
    ) {
    }
}
