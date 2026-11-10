<?php

namespace App\DataTransferObjects;

/** What a completed transfer hands back to the caller. */
final readonly class TransferResult
{
    public function __construct(
        public string $referenceId,
        public string $recipientEmail,
        public string $amount,
    ) {
    }
}
