<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['wallet_id', 'type', 'amount', 'reference_id', 'metadata'])]
class Transaction extends Model
{
    public $timestamps = false; // table only has created_at (set by DB default)

    protected function casts(): array
    {
        return [
            'amount'     => 'decimal:2',
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}