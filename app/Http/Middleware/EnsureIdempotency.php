<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Support\Problem;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || $key === '' || strlen($key) > 100) {
            return Problem::response(
                422,
                'Idempotency-Key required',
                'Send a unique Idempotency-Key header (maximum 100 characters) with this request.'
            );
        }

        $userId = $request->user()->id;
        $hash = hash('sha256', $request->getContent());

        // Claim the key first. The unique (user_id, key) index decides who wins a race.
        try {
            $record = IdempotencyKey::create([
                'user_id' => $userId,
                'key' => $key,
                'request_hash' => $hash,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->replay($userId, $key, $hash);
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            $record->update([
                'response_status' => $response->getStatusCode(),
                'response_body' => json_decode($response->getContent(), true),
            ]);
        } else {
            // Failed attempts do not consume the key, so the client can fix the problem and retry.
            $record->delete();
        }

        return $response;
    }

    private function replay(int $userId, string $key, string $hash): Response
    {
        $record = IdempotencyKey::where('user_id', $userId)->where('key', $key)->first();

        if (! $record || $record->response_status === null) {
            return Problem::response(
                409,
                'Request in progress',
                'A request with this Idempotency-Key is still being processed. Retry shortly.'
            );
        }

        if ($record->request_hash !== $hash) {
            return Problem::response(
                422,
                'Idempotency-Key reused',
                'This Idempotency-Key was already used with a different request payload.'
            );
        }

        return response()
            ->json($record->response_body, $record->response_status)
            ->header('Idempotency-Replayed', 'true');
    }
}