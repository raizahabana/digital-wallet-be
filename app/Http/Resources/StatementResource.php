<?php

namespace App\Http\Resources;

use App\Models\Statement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class StatementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_url' => route('statements.show', ['statement' => $this->id]),
            // A plain <a href> cannot send a bearer token, so the download link is short-lived and signed.
            'download_url' => $this->status === Statement::COMPLETED
                ? URL::temporarySignedRoute('statements.download', now()->addMinutes(10), ['statement' => $this->id])
                : null,
            'error' => $this->status === Statement::FAILED ? $this->error : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}