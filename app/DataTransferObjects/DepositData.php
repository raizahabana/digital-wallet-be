<?php

namespace App\DataTransferObjects;

use App\Http\Requests\Api\V1\DepositRequest;
use App\Models\User;

/** Validated input for a deposit. */
final readonly class DepositData
{
    public function __construct(
        public User $user,
        public string $amount,
    ) {
    }

    public static function fromRequest(DepositRequest $request): self
    {
        return new self($request->user(), (string) $request->validated('amount'));
    }
}
