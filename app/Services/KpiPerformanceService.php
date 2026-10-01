<?php

namespace App\Services;

use App\Models\KpiActual;
use App\Models\KpiDefinition;
use App\Models\KpiProfileMission;
use App\Models\KpiProfileTarget;
use App\Models\KpiTarget;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Support\KpiPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The single place KPI performance is calculated (FR-KPI-003, 006, 008, 009,
 * 016). The KPI dashboard, comparison matrix, target plan, performance
 * report, HRM&D views and the home dashboards all read their numbers from
 * evaluate(), so no two screens can disagree about a KPI.
 *
 * For one period, each mission and KPI gets:
 * - the target: the half-yearly cycle target (a mission override, else the
 *   mission's KPI Profile default), split evenly across the cycle's two
 *   quarters and summed over the period's quarters;
 * - the actual: per quarter, counted live from the engine tables for a KPI
 *   with a recognised data source (KpiDataSources), else read from
 *   kpi_actuals, and summed over the period (FR-KPI-016);
 * - the status (FR-KPI-008), judged against the target expected by now: the
 *   share of the target for the quarters already settled (past their
 *   reporting deadline, KpiPeriod::progress()), the full target once every
 *   quarter has, and "pending" before the first one has.
 */
class KpiPerformanceService
{
    public const string ON_TRACK = 'on_track';

    public const string AT_RISK = 'at_risk';

    public const string BELOW_TARGET = 'below_target';

    public const string NO_TARGET = 'no_target';

    public const string NO_DATA = 'no_data';

    public const string PENDING = 'pending';

    /**
     * @var array<int, string>
     */
    public const array STATUSES = [
        self::ON_TRACK,
        self::AT_RISK,
        self::BELOW_TARGET,
        self::PENDING,
        self::NO_DATA,
        self::NO_TARGET,
    ];

    public function __construct(private readonly KpiDataSources $dataSources) {}

    /**
     * @return array{on_track: float, at_risk: float}
     */
    public function thresholds(): array
    {
        return [
            'on_track' => (float) config('kpi.thresholds.on_track', 1.0),
            'at_risk' => (float) config('kpi.thresholds.at_risk', 0.75),
        ];
    }

    public function today(): CarbonImmutable
    {
        return Carbon::today()->toImmutable();
    }

    /**
     * The department's active KPI library, by name.
     *
     * @return Collection<int, KpiDefinition>
     */
    public function definitions(string $ministryId): Collection
    {
        return KpiDefinition::query()
            ->withoutGlobalScopes()
            ->where('ministry_id', $ministryId)
            ->where('active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * The department's active postings (mission_ministry_links), by name.
     * Missions are never deleted, only made inactive (BR-003); an inactive
     * mission carries no KPIs.
     *
     * @return Collection<int, Mission>
     */
    public function missions(string $ministryId): Collection
    {
        return MissionMinistryLink::query()
            ->where('ministry_id', $ministryId)
            ->with('mission')
            ->get()
            ->pluck('mission')
            ->filter(fn (?Mission $mission): bool => $mission !== null && $mission->active)
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /**
     * FR-KPI-002 AC1: a mission on a KPI Profile tracks the profile's KPIs; a
     * mission on no profile tracks the whole library.
     *
     * @param  Collection<int, Mission>  $missions
     * @param  Collection<int, KpiDefinition>  $definitions
     * @return array<string, array{profiles: array<int, array{id: string, name: string}>, kpi_ids: array<int, string>, profile_ids_by_kpi: array<string, array<int, string>>}>
     */
    public function applicability(string $ministryId, Collection $missions, Collection $definitions): array
    {
        $activeIds = $definitions->pluck('id')->all();

        $assignments = KpiProfileMission::query()
            ->withoutGlobalScopes()
            ->whereIn('mission_id', $missions->pluck('id'))
            ->whereHas('kpiProfile', fn ($query) => $query->withoutGlobalScopes()->where('ministry_id', $ministryId))
            ->with(['kpiProfile' => fn ($query) => $query->withoutGlobalScopes()->with(['kpiProfileDefinitions' => fn ($inner) => $inner->withoutGlobalScopes()])])
            ->get()
            ->sortBy(fn (KpiProfileMission $assignment): string => $assignment->kpiProfile->name)
            ->groupBy('mission_id');

        $map = [];
        foreach ($missions as $mission) {
            $missionAssignments = $assignments->get($mission->id, collect());

            if ($missionAssignments->isEmpty()) {
                $map[$mission->id] = ['profiles' => [], 'kpi_ids' => $activeIds, 'profile_ids_by_kpi' => []];

                continue;
            }

            $profileIdsByKpi = [];
            foreach ($missionAssignments as $assignment) {
                foreach ($assignment->kpiProfile->kpiProfileDefinitions as $profileDefinition) {
                    if (in_array($profileDefinition->kpi_definition_id, $activeIds, true)) {
                        $profileIdsByKpi[$profileDefinition->kpi_definition_id][] = $assignment->kpi_profile_id;
                    }
                }
            }

            $map[$mission->id] = [
                'profiles' => $missionAssignments
                    ->map(fn (KpiProfileMission $assignment): array => ['id' => $assignment->kpiProfile->id, 'name' => $assignment->kpiProfile->name])
                    ->values()
                    ->all(),
                'kpi_ids' => array_values(array_filter($activeIds, fn (string $id): bool => isset($profileIdsByKpi[$id]))),
                'profile_ids_by_kpi' => $profileIdsByKpi,
            ];
        }

        return $map;
    }

    /**
     * FR-KPI-003/004: the target in force for each mission, KPI and
     * half-yearly cycle — the latest mission override, else the latest
     * default of the first of the mission's profiles that sets one. Earlier
     * versions are kept (BR-019); only the newest counts.
     *
     * @param  Collection<int, Mission>  $missions
     * @param  Collection<int, KpiDefinition>  $definitions
     * @param  array<string, array{profiles: array<int, array{id: string, name: string}>, kpi_ids: array<int, string>, profile_ids_by_kpi: array<string, array<int, string>>}>  $applicability
     * @param  array<int, string>  $cycleLabels
     * @return array<string, array<string, array<string, array{value: float, source: string, override: ?KpiTarget, profile_default: ?KpiProfileTarget}>>>
     */
    public function effectiveTargets(Collection $missions, Collection $definitions, array $applicability, array $cycleLabels): array
    {
        $missionIds = $missions->pluck('id')->all();
        $kpiIds = $definitions->pluck('id')->all();

        if ($missionIds === [] || $kpiIds === [] || $cycleLabels === []) {
            return [];
        }

        $overrides = $this->latestOverrides($missionIds, $kpiIds, $cycleLabels);
        $defaults = $this->latestProfileDefaults(
            collect($applicability)->flatMap(fn (array $entry): array => array_column($entry['profiles'], 'id'))->unique()->values()->all(),
            $kpiIds,
            $cycleLabels,
        );

        $targets = [];
        foreach ($missionIds as $missionId) {
            foreach ($applicability[$missionId]['kpi_ids'] ?? [] as $kpiId) {
                foreach ($cycleLabels as $label) {
                    $override = $overrides["{$missionId}|{$kpiId}|{$label}"] ?? null;
                    $default = null;
                    foreach ($applicability[$missionId]['profile_ids_by_kpi'][$kpiId] ?? [] as $profileId) {
                        $default = $defaults["{$profileId}|{$kpiId}|{$label}"] ?? null;
                        if ($default !== null) {
                            break;
                        }
                    }

                    $chosen = $override ?? $default;
                    if ($chosen !== null) {
                        $targets[$missionId][$kpiId][$label] = [
                            'value' => (float) $chosen->target_value,
                            'source' => $override !== null ? 'mission' : 'profile',
                            'override' => $override,
                            'profile_default' => $default,
                        ];
                    }
                }
            }
        }

        return $targets;
    }

    /**
     * @param  array<int, string>  $missionIds
     * @param  array<int, string>  $kpiIds
     * @param  array<int, string>  $cycleLabels
     * @return array<string, KpiTarget> keyed "mission|kpi|cycle"
     */
    public function latestOverrides(array $missionIds, array $kpiIds, array $cycleLabels): array
    {
        return KpiTarget::query()
            ->withoutGlobalScopes()
            ->with('setBy')
            ->whereIn('mission_id', $missionIds)
            ->whereIn('kpi_definition_id', $kpiIds)
            ->whereIn('performance_cycle_label', $cycleLabels)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (KpiTarget $target): string => "{$target->mission_id}|{$target->kpi_definition_id}|{$target->performance_cycle_label}")
            ->all();
    }

    /**
     * @param  array<int, string>  $profileIds
     * @param  array<int, string>  $kpiIds
     * @param  array<int, string>  $cycleLabels
     * @return array<string, KpiProfileTarget> keyed "profile|kpi|cycle"
     */
    public function latestProfileDefaults(array $profileIds, array $kpiIds, array $cycleLabels): array
    {
        if ($profileIds === []) {
            return [];
        }

        return KpiProfileTarget::query()
            ->withoutGlobalScopes()
            ->with('setBy')
            ->whereIn('kpi_profile_id', $profileIds)
            ->whereIn('kpi_definition_id', $kpiIds)
            ->whereIn('performance_cycle_label', $cycleLabels)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (KpiProfileTarget $target): string => "{$target->kpi_profile_id}|{$target->kpi_definition_id}|{$target->performance_cycle_label}")
            ->all();
    }

    /**
     * Per-quarter actuals for every quarter from $from to $to: live counts
     * for KPIs with a recognised data source (zero for a quarter that has
     * begun and recorded nothing), stored kpi_actuals values otherwise.
     *
     * @param  Collection<int, Mission>  $missions
     * @param  Collection<int, KpiDefinition>  $definitions
     * @return array<string, array<string, array<string, float>>> mission id, KPI id, quarter start
     */
    public function quarterlyActuals(string $ministryId, Collection $missions, Collection $definitions, CarbonInterface $from, CarbonInterface $to): array
    {
        $missionIds = $missions->pluck('id')->all();
        if ($missionIds === [] || $definitions->isEmpty()) {
            return [];
        }

        $values = [];

        $live = $definitions->filter(fn (KpiDefinition $kpi): bool => KpiDataSources::isLive($kpi));
        foreach ($live->groupBy('data_source') as $source => $kpis) {
            $counts = $this->dataSources->quarterlyCounts($source, $ministryId, $missionIds, $from, $to);
            foreach ($kpis as $kpi) {
                foreach ($counts as $missionId => $quarters) {
                    foreach ($quarters as $quarterStart => $count) {
                        $values[$missionId][$kpi->id][$quarterStart] = $count;
                    }
                }
            }
        }

        $recordedIds = $definitions->reject(fn (KpiDefinition $kpi): bool => KpiDataSources::isLive($kpi))->pluck('id')->all();
        if ($recordedIds !== []) {
            KpiActual::query()
                ->withoutGlobalScopes()
                ->whereIn('kpi_definition_id', $recordedIds)
                ->whereIn('mission_id', $missionIds)
                ->whereBetween('period_start_date', [$from->toDateString(), $to->toDateString()])
                ->get(['mission_id', 'kpi_definition_id', 'period_start_date', 'actual_value'])
                ->each(function (KpiActual $actual) use (&$values): void {
                    $values[$actual->mission_id][$actual->kpi_definition_id][$actual->period_start_date->toDateString()] = (float) $actual->actual_value;
                });
        }

        return $values;
    }

    /**
     * Every mission x KPI cell for one period.
     *
     * @param  Collection<int, Mission>  $missions
     * @param  Collection<int, KpiDefinition>  $definitions
     * @param  array<string, array{profiles: array<int, array{id: string, name: string}>, kpi_ids: array<int, string>, profile_ids_by_kpi: array<string, array<int, string>>}>  $applicability
     * @return array<string, array<string, array<string, mixed>>> mission id, then KPI id
     */
    public function evaluate(string $ministryId, Collection $missions, Collection $definitions, array $applicability, KpiPeriod $period, ?CarbonInterface $today = null): array
    {
        $today ??= $this->today();
        $targets = $this->effectiveTargets($missions, $definitions, $applicability, $period->halfLabels());
        $actuals = $this->quarterlyActuals($ministryId, $missions, $definitions, $period->start(), $period->end());

        $cells = [];
        foreach ($missions as $mission) {
            $cells[$mission->id] = [];
            foreach ($definitions as $kpi) {
                $quarters = [];
                foreach ($period->quarters as $quarter) {
                    $start = $quarter['start']->toDateString();
                    $begun = $start <= $today->toDateString();
                    $stored = $actuals[$mission->id][$kpi->id][$start] ?? null;
                    $cycleTarget = $targets[$mission->id][$kpi->id][KpiPeriod::halfLabelForQuarter($quarter['label'])] ?? null;

                    $quarters[] = [
                        'label' => $quarter['label'],
                        'start' => $start,
                        'end' => $quarter['end']->toDateString(),
                        'actual' => KpiDataSources::isLive($kpi) ? ($begun ? ($stored ?? 0.0) : null) : $stored,
                        'target' => $cycleTarget !== null ? round($cycleTarget['value'] / 2, 2) : null,
                        'target_source' => $cycleTarget['source'] ?? null,
                    ];
                }

                $cells[$mission->id][$kpi->id] = [
                    'applicable' => in_array($kpi->id, $applicability[$mission->id]['kpi_ids'] ?? [], true),
                    ...$this->measure($quarters, $period, $today),
                ];
            }
        }

        return $cells;
    }

    /**
     * The numbers and status for one set of quarters.
     *
     * @param  array<int, array{label: string, start: string, end: string, actual: ?float, target: ?float, target_source: ?string}>  $quarters
     * @return array<string, mixed>
     */
    public function measure(array $quarters, KpiPeriod $period, CarbonInterface $today): array
    {
        $reported = array_values(array_filter(array_column($quarters, 'actual'), fn (?float $value): bool => $value !== null));
        $actual = $reported === [] ? null : round(array_sum($reported), 2);

        $quarterTargets = array_column($quarters, 'target');
        $target = in_array(null, $quarterTargets, true) || $quarterTargets === [] ? null : round(array_sum($quarterTargets), 2);

        $phase = $period->phase($today);
        $progress = $period->progress($today);
        $expected = $target !== null ? round($target * $progress, 2) : null;
        $status = $this->status($target, $actual, $phase, $progress);

        $sources = array_values(array_unique(array_filter(array_column($quarters, 'target_source'))));

        return [
            'target' => $target,
            'actual' => $actual,
            'expected' => $expected,
            'attainment' => $target !== null && $target > 0 && $actual !== null ? round($actual / $target, 4) : null,
            'variance' => $target !== null && $actual !== null ? round($actual - $target, 2) : null,
            'performance' => match (true) {
                ! in_array($status, [self::ON_TRACK, self::AT_RISK, self::BELOW_TARGET], true) => null,
                $expected !== null && $expected > 0 => round($actual / $expected, 4),
                default => round($actual / $target, 4),
            },
            'status' => $status,
            'target_source' => match (count($sources)) {
                0 => null,
                1 => $sources[0],
                default => 'mixed',
            },
            'quarters_reported' => count($reported),
            'quarters' => array_map(fn (array $quarter): array => [
                'label' => $quarter['label'],
                'start' => $quarter['start'],
                'end' => $quarter['end'],
                'actual' => $quarter['actual'],
                'target' => $quarter['target'],
            ], $quarters),
        ];
    }

    /**
     * FR-KPI-008. A target already met is on track at any point. Otherwise
     * the actual is compared with the target expected by now (target x share
     * of the period's quarters settled), against the configured thresholds;
     * until a quarter has settled there is nothing fair to compare with.
     */
    public function status(?float $target, ?float $actual, string $phase, float $progress): string
    {
        if ($target === null || $target <= 0.0) {
            return self::NO_TARGET;
        }

        if ($phase === KpiPeriod::UPCOMING) {
            return self::PENDING;
        }

        if ($actual !== null && $actual >= $target) {
            return self::ON_TRACK;
        }

        if ($progress <= 0.0) {
            return self::PENDING;
        }

        if ($actual === null) {
            return self::NO_DATA;
        }

        $ratio = $actual / ($target * $progress);
        $thresholds = $this->thresholds();

        return match (true) {
            $ratio >= $thresholds['on_track'] => self::ON_TRACK,
            $ratio >= $thresholds['at_risk'] => self::AT_RISK,
            default => self::BELOW_TARGET,
        };
    }

    /**
     * Status counts and a score for a set of cells (non-applicable ones
     * skipped). The score is the mean performance of the judged KPIs, each
     * capped at 100% so over-achievement on one KPI cannot hide a shortfall
     * on another.
     *
     * @param  array<int, array<string, mixed>>  $cells
     * @return array{counts: array<string, int>, total: int, judged: int, score: ?float}
     */
    public function summarise(array $cells): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        $performances = [];

        foreach ($cells as $cell) {
            if (! $cell['applicable']) {
                continue;
            }
            $counts[$cell['status']]++;
            if ($cell['performance'] !== null) {
                $performances[] = min(1.0, (float) $cell['performance']);
            }
        }

        $judged = $counts[self::ON_TRACK] + $counts[self::AT_RISK] + $counts[self::BELOW_TARGET];

        return [
            'counts' => $counts,
            'total' => array_sum($counts),
            'judged' => $judged,
            'score' => $performances === [] ? null : round(array_sum($performances) / count($performances), 4),
        ];
    }

    /**
     * One KPI across many missions (the department total): targets and
     * actuals of the missions that have a target, judged like a single
     * mission, plus every mission's actual and the status distribution.
     *
     * @param  array<int, array<string, mixed>>  $cells
     * @return array<string, mixed>
     */
    public function combine(array $cells, KpiPeriod $period, CarbonInterface $today): array
    {
        $applicable = array_values(array_filter($cells, fn (array $cell): bool => $cell['applicable']));
        $targeted = array_values(array_filter($applicable, fn (array $cell): bool => $cell['target'] !== null));

        $quarters = [];
        foreach ($period->quarters as $index => $quarter) {
            $quarterActuals = array_values(array_filter(array_map(fn (array $cell): ?float => $cell['quarters'][$index]['actual'], $targeted), fn (?float $value): bool => $value !== null));
            $quarters[] = [
                'label' => $quarter['label'],
                'start' => $quarter['start']->toDateString(),
                'end' => $quarter['end']->toDateString(),
                'actual' => $quarterActuals === [] ? null : array_sum($quarterActuals),
                'target' => $targeted === [] ? null : array_sum(array_map(fn (array $cell): float => (float) $cell['quarters'][$index]['target'], $targeted)),
                'target_source' => null,
            ];
        }

        $allActuals = array_values(array_filter(array_column($applicable, 'actual'), fn (?float $value): bool => $value !== null));
        $distribution = array_fill_keys(self::STATUSES, 0);
        foreach ($applicable as $cell) {
            $distribution[$cell['status']]++;
        }

        return [
            ...$this->measure($quarters, $period, $today),
            'actual_all' => $allActuals === [] ? null : round(array_sum($allActuals), 2),
            'missions_applicable' => count($applicable),
            'missions_with_target' => count($targeted),
            'distribution' => $distribution,
        ];
    }
}
