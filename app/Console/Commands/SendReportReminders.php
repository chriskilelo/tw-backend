<?php

namespace App\Console\Commands;

use App\Models\Ministry;
use App\Models\MissionMinistryLink;
use App\Models\PeriodicReport;
use App\Services\NotificationService;
use App\Services\ReportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * FR-RPT-016, CLAUDE.md Section 8/12 reporting calendar: nudges the attache
 * of every mission that has not submitted its report for the period open
 * for submission — a reminder at each configured lead time before the
 * 15th-of-next-month deadline (7 and 3 days), and one overdue notice the day
 * after it. Scheduled daily (routes/console.php) so it notices those dates as
 * they arrive.
 *
 * The pending missions are recomputed fresh from
 * ReportService::getComplianceDashboard() on every run rather than queued
 * at draft creation, so a report submitted between two runs simply drops
 * out of the next run's list — no queued reminder needs cancelling. Each
 * notification links to the mission's draft, or to the start-report page
 * with the period selected (FR-NOTIF-003).
 */
#[Signature('report:send-reminders')]
#[Description('Sends report deadline reminders and overdue notices to attaches whose mission has not submitted (FR-RPT-016).')]
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
        $daysRemaining = (int) $today->diffInDays($deadlineDate, false);
        $isOverdueNotice = $daysRemaining === -1;

        if (! $isOverdueNotice && ! in_array($daysRemaining, ReportService::REMINDER_LEAD_DAYS, true)) {
            $this->info("No reminder due today ({$daysRemaining} day(s) to the {$period['label']} deadline).");

            return self::SUCCESS;
        }

        $sent = 0;
        $deadlineText = $period['deadline']->toFormattedDateString();

        foreach (Ministry::query()->where('active', true)->get() as $ministry) {
            $dashboard = $this->reportService->getComplianceDashboard($ministry->id, $period['label']);

            $pending = collect($dashboard['missions'])
                ->whereIn('status', [PeriodicReport::COMPLIANCE_DRAFT, PeriodicReport::COMPLIANCE_NOT_STARTED])
                ->keyBy('mission_id');

            if ($pending->isEmpty()) {
                continue;
            }

            $drafts = PeriodicReport::query()
                ->withoutGlobalScopes()
                ->where('ministry_id', $ministry->id)
                ->where('reporting_period_label', $period['label'])
                ->whereIn('mission_id', $pending->keys())
                ->pluck('id', 'mission_id');

            $links = MissionMinistryLink::query()
                ->where('ministry_id', $ministry->id)
                ->whereIn('mission_id', $pending->keys())
                ->whereNotNull('active_attache_user_id')
                ->with('activeAttache')
                ->get();

            foreach ($links as $posting) {
                if ($posting->activeAttache === null) {
                    continue;
                }

                $draftId = $drafts->get($posting->mission_id);

                $this->notificationService->notify(
                    $posting->activeAttache,
                    $isOverdueNotice ? 'report_overdue' : 'report_deadline_reminder',
                    $isOverdueNotice
                        ? "Your {$period['label']} periodic report is overdue: the deadline was {$deadlineText}. Submit it as soon as possible; it will be recorded as late."
                        : "Reminder: your {$period['label']} periodic report is due {$deadlineText} ({$daysRemaining} day(s) remaining).",
                    $draftId !== null ? "/reports/{$draftId}" : '/reports/new?period='.rawurlencode($period['label']),
                );
                $sent++;
            }
        }

        $what = $isOverdueNotice ? 'overdue notice(s)' : 'reminder(s)';
        $this->info("Sent {$sent} {$what} for {$period['label']} ({$daysRemaining} day(s) to deadline).");

        return self::SUCCESS;
    }
}
