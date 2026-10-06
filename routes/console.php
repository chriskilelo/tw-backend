<?php

use App\Console\Commands\ComputeKpiActuals;
use App\Console\Commands\FlagStaleDirectives;
use App\Console\Commands\PurgeExpiredPii;
use App\Console\Commands\SendDirectiveDueReminders;
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
// Notifies target and issuer once per stale episode.
Schedule::command(FlagStaleDirectives::class)->dailyAt('08:15');

// FR-DIR-004, CLAUDE.md Section 8 Reminder Schedule: the command sends
// only on the two lead times (7 and 3 days before the target completion
// date), so it runs daily to catch them as they occur.
Schedule::command(SendDirectiveDueReminders::class)->dailyAt('08:30');

// FR-KPI-006: recomputes every auto-calculated KPI's current-quarter
// actual daily so it always reflects live data; safe to re-run any number
// of times (KpiService::recordActual() upserts, see ComputeKpiActuals's
// own docblock).
Schedule::command(ComputeKpiActuals::class)->dailyAt('08:30');

// NFR-DATA-002 session task: 12-month inquirer-PII redaction sweep.
Schedule::command(PurgeExpiredPii::class)->dailyAt('08:45');
