<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'amount' => $this->amount,
            'reference_id' => $this->reference_id,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}