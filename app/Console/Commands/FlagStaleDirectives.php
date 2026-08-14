<?php

namespace App\Console\Commands;

use App\Services\DirectiveService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * FR-DIR-010, CLAUDE.md Section 8 Stale Directive Threshold: scheduled
 * daily (routes/console.php); DirectiveService::flagStaleDirectives()
 * itself re-queries in_progress directives with no progress update in the
 * last 14 days on every run, so a directive updated between two scheduled
 * runs simply stops appearing in the next run's stale list — same
 * mechanism as SendReportReminders (Session 26), no separate queued-job
 * cancellation is needed.
 */
#[Signature('directive:flag-stale')]
#[Description('Sends a stale-progress reminder for every in_progress directive with no update in the configured threshold (FR-DIR-010).')]
class FlagStaleDirectives extends Command
{
    public function __construct(private readonly DirectiveService $directiveService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->directiveService->flagStaleDirectives();

        $this->info('Stale directive reminders dispatched.');

        return self::SUCCESS;
    }
}
