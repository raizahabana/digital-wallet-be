<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    public function test_expired_sanctum_tokens_are_pruned_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command, 'sanctum:prune-expired'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
    }
}
