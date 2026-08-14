<?php

namespace App\Console\Commands;

use App\Models\Ministry;
use App\Models\MissionMinistryLink;
use App\Services\NotificationService;
use App\Services\ReportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * FR-RPT-016, CLAUDE.md Section 8 Reporting Calendar: fires only on the two
 * configured lead times ahead of the 15th-of-next-month deadline (7 days and
 * 3 days out) — scheduled to run daily (routes/console.php) so it can detect
 * those two dates as they arrive.
 *
 * The "not yet submitted" mission list is recomputed fresh from
 * ReportService::getComplianceDashboard() on every run rather than
 * pre-queued at draft-report creation time, so a report submitted between
 * two scheduled runs is simply absent from the next run's list — see
 * ReportService::submitReport()'s docblock for why this means a reminder
 * never needs an explicit queued-job cancellation.
 */
#[Signature('report:send-reminders')]
#[Description('Sends deadline reminders to attaches at missions with no submitted report for the current reporting period (FR-RPT-016).')]
class SendReportReminders extends Command
{
    public function __construct(
        private readonly ReportService $reportService,
        private readonly NotificationService $notificationService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $period = $this->reportService->currentSubmissionPeriod();

        $today = Carbon::today();
        $deadlineDate = $period['deadline']->copy()->startOfDay();
        $daysRemaining = (int) (($deadlineDate->timestamp - $today->timestamp) / 86400);

        if (! in_array($daysRemaining, [7, 3], true)) {
            $this->info("No reminder due today ({$daysRemaining} day(s) to the {$period['label']} deadline).");

            return self::SUCCESS;
        }

        $sent = 0;

        foreach (Ministry::query()->where('active', true)->get() as $ministry) {
            $dashboard = $this->reportService->getComplianceDashboard($ministry->id, $period['label']);

            $pendingMissionIds = collect($dashboard['missions'])
                ->where('status', 'not_yet_submitted')
                ->pluck('mission_id');

            if ($pendingMissionIds->isEmpty()) {
                continue;
            }

            $attaches = MissionMinistryLink::query()
                ->where('ministry_id', $ministry->id)
                ->whereIn('mission_id', $pendingMissionIds)
                ->whereNotNull('active_attache_user_id')
                ->with('activeAttache')
                ->get()
                ->pluck('activeAttache')
                ->filter();

            foreach ($attaches as $attache) {
                $this->notificationService->notify(
                    $attache,
                    'report_deadline_reminder',
                    "Reminder: your {$period['label']} periodic report is due {$period['deadline']->toFormattedDateString()} ({$daysRemaining} day(s) remaining).",
                );
                $sent++;
            }
        }

        $this->info("Sent {$sent} reminder(s) for {$period['label']} ({$daysRemaining} day(s) to deadline).");

        return self::SUCCESS;
    }
}
