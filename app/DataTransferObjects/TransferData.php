<?php

namespace App\DataTransferObjects;

use App\Http\Requests\Api\V1\TransferRequest;
use App\Models\User;

/** Validated input for a peer-to-peer transfer. Immutable, so an Action cannot be handed half-built data. */
final readonly class TransferData
{
    public function __construct(
        public User $sender,
        public string $recipientEmail,
        public string $amount,
    ) {
    }

    public static function fromRequest(TransferRequest $request): self
    {
        return new self(
            $request->user(),
            $request->validated('recipient_email'),
            (string) $request->validated('amount'),
        );
    }
}
