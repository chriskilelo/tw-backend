<?php

namespace App\Console\Commands;

use App\Services\DirectiveService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * FR-DIR-004, CLAUDE.md Section 8 Reminder Schedule: scheduled daily
 * (routes/console.php). DirectiveService::sendDueReminders() re-queries on
 * every run and only reminds on open directives due exactly 7 or 3 days
 * from today, so a directive completed, cancelled or re-dated before a
 * reminder date simply stops matching — same "no cancellation needed"
 * mechanism as SendReportReminders.
 */
#[Signature('directive:send-reminders')]
#[Description('Reminds target attaches 7 and 3 days before an open directive\'s target completion date (FR-DIR-004).')]
class SendDirectiveDueReminders extends Command
{
    public function __construct(private readonly DirectiveService $directiveService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $sent = $this->directiveService->sendDueReminders();

        $this->info("Directive due reminders sent: {$sent}.");

        return self::SUCCESS;
    }
}
