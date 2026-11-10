<?php

namespace App\Http\Resources;

use App\DataTransferObjects\TransferResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property TransferResult $resource */
class TransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'reference_id' => $this->resource->referenceId,
            'recipient_email' => $this->resource->recipientEmail,
            'amount' => $this->resource->amount,
        ];
    }
}
