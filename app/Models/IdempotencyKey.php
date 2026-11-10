<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

#[Fillable(['user_id', 'key', 'request_hash', 'response_status', 'response_body'])]
class IdempotencyKey extends Model
{
    use Prunable;

    /** Keys only need to outlive realistic client retries. */
    public const RETENTION_HOURS = 24;

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subHours(self::RETENTION_HOURS));
    }

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_body' => 'array',
        ];
    }
}