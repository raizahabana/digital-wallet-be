<?php

namespace Tests\Feature;

use App\Jobs\GenerateStatement;
use App\Models\Statement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesWallets;
use Tests\TestCase;

class StatementTest extends TestCase
{
    use CreatesWallets;
    use RefreshDatabase;

    public function test_requesting_a_statement_queues_a_job_and_returns_202(): void
    {
        Queue::fake();
        $user = $this->userWithWallet();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/wallet/statements')
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.download_url', null);

        Queue::assertPushed(GenerateStatement::class, 1);
        $this->assertDatabaseHas('statements', ['wallet_id' => $user->wallet->id, 'status' => 'queued']);

        // The status URI is returned both in the Location header and in the body.
        $this->assertNotEmpty($response->headers->get('Location'));
        $this->assertSame($response->headers->get('Location'), $response->json('data.status_url'));
    }

    public function test_the_status_endpoint_reports_progress_to_the_owner(): void
    {
        $user = $this->userWithWallet();
        $statement = Statement::create(['wallet_id' => $user->wallet->id, 'status' => Statement::PROCESSING]);
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/wallet/statements/{$statement->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'processing');
    }

    public function test_another_user_cannot_see_the_statement(): void
    {
        $owner = $this->userWithWallet();
        $statement = Statement::create(['wallet_id' => $owner->wallet->id, 'status' => Statement::QUEUED]);

        Sanctum::actingAs($this->userWithWallet());

        $this->getJson("/api/v1/wallet/statements/{$statement->id}")->assertStatus(404);
    }

    public function test_the_job_writes_a_csv_and_marks_the_statement_completed(): void
    {
        Storage::fake('local');

        $user = $this->userWithWallet();
        $user->wallet->transactions()->create(['type' => 'deposit', 'amount' => '50.00']);
        $user->wallet->transactions()->create(['type' => 'withdrawal', 'amount' => '5.00']);
        $statement = Statement::create(['wallet_id' => $user->wallet->id, 'status' => Statement::QUEUED]);

        (new GenerateStatement($statement->id))->handle();

        $statement->refresh();
        $this->assertSame(Statement::COMPLETED, $statement->status);
        Storage::disk('local')->assertExists($statement->file_path);

        $csv = Storage::disk('local')->get($statement->file_path);
        $this->assertStringContainsString('Date,Type,Amount', $csv);
        $this->assertStringContainsString('deposit', $csv);
        $this->assertStringContainsString('withdrawal', $csv);
        $this->assertCount(3, array_filter(explode("\n", $csv))); // header + 2 rows
    }

    public function test_a_failing_job_marks_the_statement_failed_with_a_generic_message(): void
    {
        $user = $this->userWithWallet();
        $statement = Statement::create(['wallet_id' => $user->wallet->id, 'status' => Statement::PROCESSING]);

        (new GenerateStatement($statement->id))->failed(new \RuntimeException('SQLSTATE secret details'));

        $statement->refresh();
        $this->assertSame(Statement::FAILED, $statement->status);
        $this->assertSame('Statement generation failed.', $statement->error);
    }

    public function test_the_download_link_must_be_signed(): void
    {
        Storage::fake('local');

        $user = $this->userWithWallet();
        $statement = Statement::create(['wallet_id' => $user->wallet->id, 'status' => Statement::QUEUED]);
        (new GenerateStatement($statement->id))->handle();

        $this->getJson("/api/v1/wallet/statements/{$statement->id}/download")->assertStatus(403);

        $signed = URL::temporarySignedRoute('statements.download', now()->addMinute(), ['statement' => $statement->id]);
        $this->get($signed)->assertOk();
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/v1/wallet/statements')->assertStatus(401);
    }
}