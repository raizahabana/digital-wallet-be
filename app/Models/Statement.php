<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['wallet_id', 'status', 'file_path', 'error'])]
class Statement extends Model
{
    use HasUuids, Prunable;

    public const QUEUED = 'queued';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    /** Generated files are deleted after this many days. */
    public const RETENTION_DAYS = 7;

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    /** Runs before the row is deleted, so the CSV on disk does not outlive its record. */
    protected function pruning(): void
    {
        if ($this->file_path) {
            Storage::disk('local')->delete($this->file_path);
        }
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}