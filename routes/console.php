<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Delete tokens that expired more than 24 hours ago.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Delete idempotency keys older than 24h and statements (plus their CSV files) older than 7 days.
Schedule::command('model:prune')->daily();
