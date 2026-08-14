<?php

namespace App\Console\Commands;

use App\Enums\InquiryStatus;
use App\Enums\PeriodicReportStatus;
use App\Models\Alert;
use App\Models\Inquiry;
use App\Models\KpiDefinition;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\PeriodicReport;
use App\Services\KpiService;
use App\Services\ReportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * FR-KPI-006: for every active KPI Definition configured with
 * calculation_method = 'auto', computes the current quarter's value from
 * its data_source engine and persists it via KpiService::recordActual().
 * "Current quarter" here means the same quarter
 * ReportService::currentSubmissionPeriod() uses — the fiscal quarter most
 * recently ended, matching CLAUDE.md Section 8's "authoritative data
 * source: quarterly reports" convention, not the quarter still in
 * progress today.
 *
 * Scheduled daily (routes/console.php) purely so "current quarter" tracks
 * today's date automatically; safe to re-run any number of times in the
 * same quarter, since recordActual() upserts on (mission, kpi, quarter)
 * rather than inserting a duplicate row each run.
 *
 * KNOWN GAP: this recognises a small, literal set of data_source keys
 * ('alerts.count_submitted', 'inquiries.count_closed',
 * 'inquiries.count_disputes_closed', 'reports.count_submitted') — the
 * exact style the session task described — rather than parsing arbitrary
 * free text. KpiDefinitionSeeder's 11 SDT KPIs actually store descriptive
 * prose in data_source ("Intelligence Alert Engine", "Periodic Report
 * Engine (Section 2 data)", etc.), not these keys, so none of them
 * currently auto-compute through this command. Flagging this drift rather
 * than silently guessing a text-to-engine mapping — a future session
 * needs to either re-seed data_source with one of the keys below (for the
 * three KPIs these keys can actually answer) or extend the recognised-key
 * list, and decide what a "Section 2 data"-sourced KPI (delegations
 * hosted, trade shows attended, forums participated in — all counted
 * from periodic_reports.report_data_rows content, not a simple table
 * count) would even mean as an auto-calculator.
 */
#[Signature('foams:compute-kpi-actuals')]
#[Description("Computes and stores this quarter's actual value for every auto-calculated KPI definition with a recognised data_source (FR-KPI-006).")]
class ComputeKpiActuals extends Command
{
    public function __construct(
        private readonly KpiService $kpiService,
        private readonly ReportService $reportService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $period = $this->reportService->currentSubmissionPeriod();
        $calculators = $this->calculators();

        $definitions = KpiDefinition::query()
            ->withoutGlobalScopes()
            ->where('calculation_method', 'auto')
            ->where('active', true)
            ->whereIn('data_source', array_keys($calculators))
            ->get();

        if ($definitions->isEmpty()) {
            $this->info('No auto-calculated KPI definitions with a recognised data_source were found.');

            return self::SUCCESS;
        }

        $computed = 0;

        foreach ($definitions as $definition) {
            $calculator = $calculators[$definition->data_source];

            $missions = MissionMinistryLink::query()
                ->where('ministry_id', $definition->ministry_id)
                ->with('mission')
                ->get()
                ->pluck('mission')
                ->filter()
                ->unique('id');

            foreach ($missions as $mission) {
                $value = $calculator($mission, $definition->ministry_id, $period['start'], $period['end']);

                $this->kpiService->recordActual(
                    $definition,
                    $mission,
                    $period['label'],
                    $period['start'],
                    $value,
                    null,
                    'auto',
                );

                $computed++;
            }
        }

        $this->info("Computed {$computed} auto KPI actual(s) for {$period['label']}.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, callable(Mission, string, Carbon, Carbon): float>
     */
    private function calculators(): array
    {
        return [
            'alerts.count_submitted' => fn (Mission $mission, string $ministryId, Carbon $start, Carbon $end): float => (float) Alert::query()
                ->withoutGlobalScopes()
                ->where('ministry_id', $ministryId)
                ->where('mission_id', $mission->id)
                ->whereBetween('created_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
                ->count(),

            'inquiries.count_closed' => fn (Mission $mission, string $ministryId, Carbon $start, Carbon $end): float => (float) Inquiry::query()
                ->withoutGlobalScopes()
                ->where('ministry_id', $ministryId)
                ->where('mission_id', $mission->id)
                ->where('status', InquiryStatus::Closed->value)
                ->whereBetween('closed_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
                ->count(),

            'inquiries.count_disputes_closed' => fn (Mission $mission, string $ministryId, Carbon $start, Carbon $end): float => (float) Inquiry::query()
                ->withoutGlobalScopes()
                ->where('ministry_id', $ministryId)
                ->where('mission_id', $mission->id)
                ->where('status', InquiryStatus::Closed->value)
                ->where('sub_type', 'dispute_or_complaint')
                ->whereBetween('closed_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
                ->count(),

            'reports.count_submitted' => fn (Mission $mission, string $ministryId, Carbon $start, Carbon $end): float => (float) PeriodicReport::query()
                ->withoutGlobalScopes()
                ->where('ministry_id', $ministryId)
                ->where('mission_id', $mission->id)
                ->where('status', PeriodicReportStatus::Submitted->value)
                ->whereBetween('submitted_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
                ->count(),
        ];
    }
}
