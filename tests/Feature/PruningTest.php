<?php

namespace Tests\Feature;

use App\Models\IdempotencyKey;
use App\Models\Statement;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

class PruningTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    private function prune(): void
    {
        Artisan::call('model:prune', ['--model' => [IdempotencyKey::class, Statement::class]]);
    }

    private function idempotencyKey(int $userId, string $key, \DateTimeInterface $createdAt): IdempotencyKey
    {
        $record = IdempotencyKey::create(['user_id' => $userId, 'key' => $key, 'request_hash' => str_repeat('a', 64)]);
        $record->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $record;
    }

    public function test_old_idempotency_keys_are_deleted_and_recent_ones_kept(): void
    {
        $user = $this->userWithWallet();
        $old = $this->idempotencyKey($user->id, 'old', now()->subHours(25));
        $fresh = $this->idempotencyKey($user->id, 'fresh', now()->subHours(1));

        $this->prune();

        $this->assertModelMissing($old);
        $this->assertModelExists($fresh);
    }

    public function test_old_statements_are_deleted_together_with_their_csv_file(): void
    {
        Storage::fake('local');
        $user = $this->userWithWallet();

        $make = function (string $path, \DateTimeInterface $createdAt) use ($user) {
            Storage::disk('local')->put($path, 'csv');
            $statement = Statement::create([
                'wallet_id' => $user->wallet->id,
                'status' => Statement::COMPLETED,
                'file_path' => $path,
            ]);
            $statement->forceFill(['created_at' => $createdAt])->saveQuietly();

            return $statement;
        };

        $old = $make('statements/old.csv', now()->subDays(8));
        $fresh = $make('statements/fresh.csv', now()->subDay());

        $this->prune();

        $this->assertModelMissing($old);
        Storage::disk('local')->assertMissing('statements/old.csv');
        $this->assertModelExists($fresh);
        Storage::disk('local')->assertExists('statements/fresh.csv');
    }

    public function test_pruning_runs_daily_on_the_scheduler(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command, 'model:prune'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
    }
}
