<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IssueTokenRequest;
use App\Models\User;
use App\Support\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class AuthTokenController extends Controller
{
    public function __invoke(IssueTokenRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return Problem::response(
                401,
                'Invalid credentials',
                'The provided email or password is incorrect.'
            );
        }

        $expiresAt = now()->addHours(2);
        $token = $user->createToken('api', ['*'], $expiresAt);

        return response()->json([
            'data' => [
                'token'      => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);
    }
}
