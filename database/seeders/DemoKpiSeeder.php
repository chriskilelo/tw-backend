<?php

namespace Database\Seeders;

use App\Models\Alert;
use App\Models\Inquiry;
use App\Models\KpiDefinition;
use App\Models\Mission;
use App\Models\PeriodicReport;
use App\Models\User;
use App\Services\KpiService;
use Database\Seeders\Support\DemoManifest;
use Database\Seeders\Support\Determinism;
use Database\Seeders\Support\FiscalQuarters;
use Database\Seeders\Support\MissionTradeProfiles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Seeds KPI targets (5 half-year cycles x 17 missions x 11 active KPI
 * definitions) and actuals (9 quarters x 17 x 11) via the real KpiService.
 * Targets are uniform per KPI, scaled only by mission headcount (a fair,
 * tier-independent workload baseline) — never lowered for struggling
 * missions, since that would make every mission trivially "on track" and
 * defeat the point. Auto-calculated actuals are queried from the
 * alerts/inquiries/reports this run already seeded (guaranteeing they
 * match what the reports/dashboards actually show); manual actuals are
 * formulaic by performance tier. This is what actually produces the mix of
 * KPI attainment and non-attainment the user asked for.
 *
 * Never touches kpi_definitions (the /sdt/config/kpi-settings data) — only
 * reads the 11 active rows KpiDefinitionSeeder already created.
 */
class DemoKpiSeeder extends Seeder
{
    /**
     * @var array<string, int>
     */
    private const array TARGET_BASE_PER_HALF = [
        'Number of quarterly reports submitted' => 2,
        'Number of market intelligence survey reports submitted' => 5,
        'Number of inquiries resolved' => 3,
        'Number of agreements signed' => 1,
        'Number of trade forums participated in' => 2,
        'Increased exports of Kenyan products' => 2,
        'Number of trade shows and exhibitions attended' => 1,
        'Number of dispute resolutions resolved' => 1,
        'Number of Kenyan delegations hosted' => 2,
        'Number of trade briefs submitted' => 2,
        'Number of engagement strategies submitted' => 1,
    ];

    /**
     * KPIs whose target scales with mission headcount. Deliberately
     * excludes every KPI structurally capped at one occurrence per quarter
     * (reports submitted, trade shows/delegations/forums, disputes
     * resolved) and the manual KPIs — scaling those by headcount would set
     * an impossible-to-reach target for larger missions (e.g. "reports
     * submitted" can never exceed 2 per half-year cycle no matter the
     * headcount, per BR-007's one-report-per-mission-per-period rule).
     *
     * @var array<int, string>
     */
    private const array HEADCOUNT_SCALED_KPIS = [
        'Number of market intelligence survey reports submitted',
        'Number of inquiries resolved',
    ];

    private const array MANUAL_KPIS = [
        'Number of agreements signed',
        'Increased exports of Kenyan products',
        'Number of trade briefs submitted',
        'Number of engagement strategies submitted',
    ];

    public function seed(DemoManifest $manifest, array $roster, string $ministryId): void
    {
        $kpiService = app(KpiService::class);
        $definitions = KpiDefinition::query()
            ->withoutGlobalScopes()
            ->where('ministry_id', $ministryId)
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->keyBy('name');

        $missions = Mission::query()->whereIn('name', array_keys(MissionTradeProfiles::all()))->get()->keyBy('name');
        $setter = $roster['hq']['hq_director'];
        $enteredBy = $roster['hq']['hq_officer_1'];

        $this->seedTargets($manifest, $kpiService, $definitions, $missions, $setter);
        $this->seedActuals($manifest, $kpiService, $definitions, $missions, $enteredBy);
    }

    /**
     * @param  Collection<string, KpiDefinition>  $definitions
     * @param  Collection<string, Mission>  $missions
     */
    private function seedTargets(DemoManifest $manifest, KpiService $kpiService, $definitions, $missions, User $setter): void
    {
        foreach (FiscalQuarters::kpiCycles() as $cycle) {
            $cycleStart = FiscalQuarters::quarterStart($cycle['year'], $cycle['half'] === 1 ? 1 : 3)['start'];

            foreach (MissionTradeProfiles::all() as $missionName => $profile) {
                $mission = $missions[$missionName];
                $headcountMultiplier = 1.0 + (0.3 * count($profile['junior_attaches']));

                foreach ($definitions as $kpiName => $kpi) {
                    $multiplier = in_array($kpiName, self::HEADCOUNT_SCALED_KPIS, true) ? $headcountMultiplier : 1.0;
                    $target = max(1, (int) round(self::TARGET_BASE_PER_HALF[$kpiName] * $multiplier));

                    Carbon::setTestNow($cycleStart->copy()->subDays(5));
                    $kpiTarget = $kpiService->setTarget($kpi, $mission, $cycle['label'], $cycleStart, (float) $target, $setter);
                    $manifest->add('kpi_targets', $kpiTarget->id);
                    Carbon::setTestNow();
                }
            }
        }
    }

    /**
     * @param  Collection<string, KpiDefinition>  $definitions
     * @param  Collection<string, Mission>  $missions
     */
    private function seedActuals(DemoManifest $manifest, KpiService $kpiService, $definitions, $missions, User $enteredBy): void
    {
        $quarters = FiscalQuarters::reportQuarters();

        foreach (MissionTradeProfiles::all() as $missionName => $profile) {
            $mission = $missions[$missionName];

            foreach ($quarters as $index => $quarter) {
                $reportSubmitted = PeriodicReport::query()
                    ->withoutGlobalScopes()
                    ->where('mission_id', $mission->id)
                    ->where('status', 'submitted')
                    ->where('period_start_date', $quarter['start']->toDateString())
                    ->exists();

                $beat = $profile['beats'][$index];

                $values = [
                    'Number of quarterly reports submitted' => $reportSubmitted ? 1 : 0,
                    'Number of market intelligence survey reports submitted' => Alert::query()->withoutGlobalScopes()
                        ->where('mission_id', $mission->id)
                        ->whereBetween('created_at', [$quarter['start'], $quarter['end']])
                        ->count(),
                    'Number of inquiries resolved' => Inquiry::query()->withoutGlobalScopes()
                        ->where('mission_id', $mission->id)
                        ->where('status', 'closed')
                        ->whereBetween('closed_at', [$quarter['start'], $quarter['end']])
                        ->count(),
                    'Number of dispute resolutions resolved' => Inquiry::query()->withoutGlobalScopes()
                        ->where('mission_id', $mission->id)
                        ->where('sub_type', 'dispute_or_complaint')
                        ->where('status', 'closed')
                        ->whereBetween('closed_at', [$quarter['start'], $quarter['end']])
                        ->count(),
                    'Number of trade forums participated in' => $reportSubmitted && $beat['type'] === 'forum' ? 1 : 0,
                    'Number of trade shows and exhibitions attended' => $reportSubmitted && $beat['type'] === 'trade_show' ? 1 : 0,
                    'Number of Kenyan delegations hosted' => $reportSubmitted && $beat['type'] === 'delegation' ? 1 : 0,
                ];

                foreach ($values as $kpiName => $value) {
                    $this->recordActual($manifest, $kpiService, $definitions[$kpiName], $mission, $quarter, $index, $value, null, 'auto');
                }

                foreach (self::MANUAL_KPIS as $kpiName) {
                    $target = self::TARGET_BASE_PER_HALF[$kpiName];
                    $ratio = match ($profile['tier']) {
                        'high' => Determinism::seeded("{$missionName}-{$index}-{$kpiName}-ratio", 100, 135) / 100,
                        'struggling' => Determinism::seeded("{$missionName}-{$index}-{$kpiName}-ratio", 25, 70) / 100,
                        default => Determinism::seeded("{$missionName}-{$index}-{$kpiName}-ratio", 65, 105) / 100,
                    };
                    $value = (int) round(($target / 2) * $ratio);

                    $this->recordActual($manifest, $kpiService, $definitions[$kpiName], $mission, $quarter, $index, $value, $enteredBy, 'manual');
                }
            }
        }
    }

    /**
     * @param  array{label: string, fy: string, start: Carbon, end: Carbon, deadline: Carbon}  $quarter
     */
    private function recordActual(
        DemoManifest $manifest,
        KpiService $kpiService,
        KpiDefinition $kpi,
        Mission $mission,
        array $quarter,
        int $index,
        int $value,
        ?User $enteredBy,
        string $calculationType,
    ): void {
        $recordMoment = $index === 8
            ? FiscalQuarters::currentDateCap()
            : $quarter['end']->copy()->addDays(3);

        Carbon::setTestNow($recordMoment);
        $actual = $kpiService->recordActual($kpi, $mission, $quarter['label'], $quarter['start'], (float) $value, $enteredBy, $calculationType);
        $manifest->add('kpi_actuals', $actual->id);
        Carbon::setTestNow();
    }
}
