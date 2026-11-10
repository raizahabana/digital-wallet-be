<?php

namespace App\Jobs;

use App\Models\Statement;
use App\Models\Transaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateStatement implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $statementId)
    {
    }

    public function handle(): void
    {
        $statement = Statement::findOrFail($this->statementId);
        $statement->update(['status' => Statement::PROCESSING]);

        $disk = Storage::disk('local');
        $path = "statements/{$statement->id}.csv";
        $disk->makeDirectory('statements');

        $handle = fopen($disk->path($path), 'w');

        try {
            fputcsv($handle, ['Date', 'Type', 'Amount', 'Reference', 'Counterparty wallet'], ',', '"', '');

            Transaction::where('wallet_id', $statement->wallet_id)
                ->chunkById(500, function ($transactions) use ($handle) {
                    foreach ($transactions as $transaction) {
                        fputcsv($handle, [
                            $transaction->created_at?->toIso8601String(),
                            $transaction->type,
                            $transaction->amount,
                            $transaction->reference_id,
                            $transaction->metadata['counterparty_wallet_id'] ?? '',
                        ], ',', '"', '');
                    }
                });
        } finally {
            fclose($handle);
        }

        $statement->update(['status' => Statement::COMPLETED, 'file_path' => $path]);
    }

    public function failed(Throwable $exception): void
    {
        // The real exception is in the failed-jobs log; the client only sees a generic message.
        Statement::whereKey($this->statementId)->update([
            'status' => Statement::FAILED,
            'error' => 'Statement generation failed.',
        ]);
    }
}