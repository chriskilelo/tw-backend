<?php

use App\Console\Commands\FlagStaleDirectives;
use App\Console\Commands\SendReportReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// FR-RPT-016: the command itself only actually sends reminders on the two
// configured lead times (7 and 3 days before the deadline, CLAUDE.md
// Section 8) — it is scheduled daily so it can detect those two dates as
// they occur.
Schedule::command(SendReportReminders::class)->dailyAt('08:00');

// FR-DIR-010, CLAUDE.md Section 8 Stale Directive Threshold (14 days).
Schedule::command(FlagStaleDirectives::class)->dailyAt('08:15');
