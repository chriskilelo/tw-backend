<?php

namespace App\Console\Commands;

use App\Models\KpiDefinition;
use App\Models\MissionMinistryLink;
use App\Services\KpiDataSources;
use App\Services\KpiService;
use App\Services\ReportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * FR-KPI-006: for every active KPI Definition calculated live
 * (calculation_method 'auto' with an App\Services\KpiDataSources key),
 * snapshots the current quarter's value into kpi_actuals via
 * KpiService::recordActual(). "Current quarter" is the quarter
 * ReportService::currentSubmissionPeriod() uses — the fiscal quarter most
 * recently ended. The dashboards compute these KPIs live; the snapshot keeps
 * a stored record of what each quarter closed at.
 *
 * Scheduled daily (routes/console.php) and safe to re-run: recordActual()
 * upserts on (mission, kpi, quarter). The counting itself lives in
 * KpiDataSources, shared with the dashboards, so a snapshot always equals
 * what the dashboards showed. The three Section 2 KPIs (forums, trade shows,
 * delegations) are not countable — Section 2 is narrative — so they keep a
 * descriptive data_source and are recorded by hand.
 */
#[Signature('foams:compute-kpi-actuals')]
#[Description("Computes and stores this quarter's actual value for every auto-calculated KPI definition with a recognised data_source (FR-KPI-006).")]
class ComputeKpiActuals extends Command
{
    public function __construct(
        private readonly KpiService $kpiService,
        private readonly ReportService $reportService,
        private readonly KpiDataSources $dataSources,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $period = $this->reportService->currentSubmissionPeriod();

        $definitions = KpiDefinition::query()
            ->withoutGlobalScopes()
            ->where('calculation_method', 'auto')
            ->where('active', true)
            ->whereIn('data_source', KpiDataSources::KEYS)
            ->get();

        if ($definitions->isEmpty()) {
            $this->info('No auto-calculated KPI definitions with a recognised data_source were found.');

            return self::SUCCESS;
        }

        $computed = 0;

        foreach ($definitions as $definition) {
            $missions = MissionMinistryLink::query()
                ->where('ministry_id', $definition->ministry_id)
                ->with('mission')
                ->get()
                ->pluck('mission')
                ->filter()
                ->unique('id');

            $counts = $this->dataSources->quarterlyCounts(
                $definition->data_source,
                $definition->ministry_id,
                $missions->pluck('id')->all(),
                $period['start'],
                $period['end'],
            );

            foreach ($missions as $mission) {
                $this->kpiService->recordActual(
                    $definition,
                    $mission,
                    $period['label'],
                    $period['start'],
                    $counts[$mission->id][$period['start']->toDateString()] ?? 0.0,
                    null,
                    'auto',
                );

                $computed++;
            }
        }

        $this->info("Computed {$computed} auto KPI actual(s) for {$period['label']}.");

        return self::SUCCESS;
    }
}
