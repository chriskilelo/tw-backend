<?php

namespace App\Console\Commands;

use App\Services\DirectiveService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * FR-DIR-010, CLAUDE.md Section 8 Stale Directive Threshold: scheduled
 * daily (routes/console.php). DirectiveService::flagStaleDirectives()
 * re-queries open directives with no progress in the last 14 days on every
 * run and notifies the target attache and the issuer once per stale
 * episode; a progress update starts a new episode, and a directive updated
 * between two runs simply stops matching (same mechanism as
 * SendReportReminders).
 */
#[Signature('directive:flag-stale')]
#[Description('Notifies the target and issuer of every open directive with no progress update in the configured threshold, once per stale episode (FR-DIR-010).')]
class FlagStaleDirectives extends Command
{
    public function __construct(private readonly DirectiveService $directiveService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $sent = $this->directiveService->flagStaleDirectives();

        $this->info("Stale directive reminders sent: {$sent}.");

        return self::SUCCESS;
    }
}
