<?php

use Illuminate\Console\Scheduling\Schedule;

/**
 * routes/console.php: verifies the two Stage-2 scheduled commands are
 * actually registered on the application schedule (not just present as
 * Artisan commands) and fire at their documented lead times — CLAUDE.md
 * Section 8 Reminder Schedule (FR-RPT-016) and Stale Directive Threshold
 * (FR-DIR-010).
 */
it('registers report:send-reminders on the daily 08:00 schedule (FR-RPT-016)', function () {
    $schedule = app(Schedule::class);

    $event = collect($schedule->events())
        ->first(fn ($scheduledEvent) => str_contains($scheduledEvent->command ?? '', 'report:send-reminders'));

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 8 * * *');
});

it('registers directive:flag-stale on the daily 08:15 schedule (FR-DIR-010)', function () {
    $schedule = app(Schedule::class);

    $event = collect($schedule->events())
        ->first(fn ($scheduledEvent) => str_contains($scheduledEvent->command ?? '', 'directive:flag-stale'));

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('15 8 * * *');
});

it('registers tw:purge-pii on the daily 08:45 schedule (NFR-DATA-002)', function () {
    $schedule = app(Schedule::class);

    $event = collect($schedule->events())
        ->first(fn ($scheduledEvent) => str_contains($scheduledEvent->command ?? '', 'tw:purge-pii'));

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('45 8 * * *');
});
