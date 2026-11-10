<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class Problem
{
    public static function response(int $status, string $title, string $detail, array $extra = []): JsonResponse
    {
        return response()->json(
            ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail] + $extra,
            $status,
            ['Content-Type' => 'application/problem+json']
        );
    }
}