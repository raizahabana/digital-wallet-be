<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class LogoutController extends Controller
{
    /** Revokes the bearer token that made this request. */
    public function __invoke(Request $request): Response
    {
        $token = $request->user()->currentAccessToken();

        // Only a real, stored token can be deleted (a transient token has no row).
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }
}
