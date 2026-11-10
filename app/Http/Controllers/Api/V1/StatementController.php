<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StatementResource;
use App\Jobs\GenerateStatement;
use App\Models\Statement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatementController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $wallet = $request->user()->wallet()->firstOrFail();

        $statement = Statement::create([
            'wallet_id' => $wallet->id,
            'status' => Statement::QUEUED,
        ]);

        GenerateStatement::dispatch($statement->id);

        return (new StatementResource($statement))
            ->response()
            ->setStatusCode(202)
            ->header('Location', route('statements.show', ['statement' => $statement->id]));
    }

    public function show(Request $request, string $statement): StatementResource
    {
        $walletId = $request->user()->wallet()->value('id');

        $record = Statement::where('id', $statement)
            ->where('wallet_id', $walletId)
            ->firstOrFail(); // someone else's statement looks exactly like a missing one

        return new StatementResource($record);
    }

    public function download(string $statement): StreamedResponse
    {
        $record = Statement::where('id', $statement)
            ->where('status', Statement::COMPLETED)
            ->firstOrFail();

        abort_unless($record->file_path && Storage::disk('local')->exists($record->file_path), 404);

        return Storage::disk('local')->download($record->file_path, "statement-{$record->id}.csv");
    }
}