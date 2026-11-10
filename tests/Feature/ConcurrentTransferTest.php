<?php

namespace Tests\Feature;

use App\Models\Transaction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

/**
 * Real concurrency: separate OS processes, each with its own database connection, all racing
 * for the same wallet. A single-connection test cannot expose a missing row lock; this can.
 *
 * Needs MySQL/PostgreSQL. DatabaseMigrations (not RefreshDatabase) is used on purpose: the
 * data must be committed so the other processes can see it.
 */
class ConcurrentTransferTest extends TestCase
{
    use CreatesWallets;
    use DatabaseMigrations;

    public function test_parallel_transfers_can_never_overdraw_the_sender(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite ignores row locks and cannot share an in-memory database across processes.');
        }

        $sender = $this->userWithWallet('100.00');
        $recipient = $this->userWithWallet('0.00');

        $workers = 6;     // 6 x 30.00 requested against a balance of 100.00
        $amount = '30.00';
        $startAt = microtime(true) + 2.0; // enough time for every child to boot first

        $processes = [];
        for ($i = 0; $i < $workers; $i++) {
            $process = new Process(
                [PHP_BINARY, base_path('tests/Support/transfer_worker.php'), (string) $sender->id, $recipient->email, $amount, (string) $startAt],
                base_path(),
                $this->childEnvironment(),
            );
            $process->start();
            $processes[] = $process;
        }

        $succeeded = 0;
        $rejected = 0;
        foreach ($processes as $process) {
            $process->wait();

            match ($process->getExitCode()) {
                0 => $succeeded++,
                2 => $rejected++,
                default => $this->fail('Worker crashed (a deadlock would show up here): '.$process->getErrorOutput()),
            };
        }

        // 100.00 funds exactly three 30.00 transfers; the other three must be refused.
        $this->assertSame(3, $succeeded, 'Exactly three transfers fit into the balance.');
        $this->assertSame(3, $rejected);

        $this->assertSame('10.00', $this->balanceOf($sender), 'The sender must never go negative.');
        $this->assertSame('90.00', $this->balanceOf($recipient));

        // Money is conserved and the ledger matches: 3 transfers = 6 linked entries.
        $this->assertSame(6, Transaction::count());
        $this->assertSame(3, Transaction::where('type', 'transfer_out')->count());
        $this->assertSame(3, Transaction::where('type', 'transfer_in')->count());
    }

    /** @return array<string, string> */
    private function childEnvironment(): array
    {
        $connection = config('database.connections.'.config('database.default'));

        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => config('database.default'),
            'DB_HOST' => (string) ($connection['host'] ?? ''),
            'DB_PORT' => (string) ($connection['port'] ?? ''),
            'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) ($connection['username'] ?? ''),
            'DB_PASSWORD' => (string) ($connection['password'] ?? ''),
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'null', // drop the TransferCompleted listener job
            'SESSION_DRIVER' => 'array',
        ];
    }
}
