<?php

namespace App\Services;

use App\Models\KpiActual;
use App\Models\KpiDefinition;
use App\Models\KpiProfile;
use App\Models\KpiProfileMission;
use App\Models\KpiProfileTarget;
use App\Models\KpiTarget;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\PeriodicReport;
use App\Models\User;
use App\Support\KpiPeriod;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * CLAUDE.md Section 11 / FR-KPI-001 to 016, FR-SDT-016 to 018: the KPI
 * Framework Engine. Configuration (definitions, profiles), target setting
 * and actual recording are mutations here; every number shown on a screen
 * comes from KpiPerformanceService::evaluate(), wrapped into the read
 * models below (dashboard, comparison, target plan, report, HRM&D views).
 * Controllers stay thin and delegate here.
 */
class KpiService
{
    /**
     * How many quarters before the current one actuals may still be
     * recorded or corrected for; older quarters are closed.
     */
    public const int ACTUAL_ENTRY_QUARTERS_BACK = 4;

    /**
     * Quarters drawn in each KPI's trend.
     */
    private const int TREND_QUARTERS = 8;

    public function __construct(
        private readonly AuditService $auditService,
        private readonly KpiPerformanceService $performance,
    ) {}

    // --- Configuration (FR-KPI-001, 002) ---------------------------------

    /**
     * FR-KPI-001. calculation_method is either 'auto' (derived from the
     * data_source field, e.g. a Layer 2 engine metric) or 'manual'.
     *
     * @param  array<string, mixed>  $data  Already validated by StoreKpiDefinitionRequest.
     */
    public function defineKpi(array $data, User $admin): KpiDefinition
    {
        // KpiDefinition is registered on App\Observers\ModelObserver, so this
        // create() is written to audit_logs as `kpi_definition.created`.
        return KpiDefinition::create([
            'ministry_id' => $data['ministry_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'unit' => $data['unit'] ?? null,
            'calculation_method' => $data['calculation_method'],
            'data_source' => $data['data_source'] ?? null,
            'reporting_frequency' => $data['reporting_frequency'],
            'active' => $data['active'] ?? true,
        ]);
    }

    /**
     * FR-KPI-002: a reusable, named group of KPI definitions.
     *
     * @param  array<string, mixed>  $data  Already validated by StoreKpiProfileRequest.
     */
    public function createProfile(array $data, User $admin): KpiProfile
    {
        $kpiIds = array_values(array_unique($data['kpi_definition_ids'] ?? []));
        $ownKpis = KpiDefinition::query()->withoutGlobalScopes()->where('ministry_id', $data['ministry_id'])->whereIn('id', $kpiIds)->count();
        if ($ownKpis !== count($kpiIds)) {
            throw new InvalidArgumentException('A KPI profile can only include KPIs of its own department.');
        }

        $data['kpi_definition_ids'] = $kpiIds;

        return DB::transaction(function () use ($data) {
            $profile = KpiProfile::create([
                'ministry_id' => $data['ministry_id'],
                'name' => $data['name'],
            ]);

            foreach ($data['kpi_definition_ids'] ?? [] as $kpiDefinitionId) {
                $profile->kpiProfileDefinitions()->create([
                    'kpi_definition_id' => $kpiDefinitionId,
                ]);
            }

            return $profile;
        });
    }

    /**
     * FR-KPI-002/003: replaces the full set of missions a profile is
     * assigned to with $missionIds (repeating a call is a no-op, API-001
     * Section 10). A mission follows one profile ("its assigned KPI
     * Profile", FR-KPI-003), so assigning it here moves it off any other
     * profile of the same department.
     *
     * @param  array<int, string>  $missionIds
     */
    public function assignProfileToMissions(KpiProfile $profile, array $missionIds, User $admin): void
    {
        $missionIds = array_values(array_unique($missionIds));
        $postings = $this->performance->missions($profile->ministry_id)->pluck('id')->all();
        if (array_diff($missionIds, $postings) !== []) {
            throw new InvalidArgumentException('A KPI profile can only be assigned to active postings of its own department.');
        }

        DB::transaction(function () use ($profile, $missionIds, $admin) {
            $profile->kpiProfileMissions()
                ->whereNotIn('mission_id', $missionIds)
                ->delete();

            KpiProfileMission::query()
                ->withoutGlobalScopes()
                ->whereIn('mission_id', $missionIds)
                ->where('kpi_profile_id', '!=', $profile->id)
                ->whereIn('kpi_profile_id', KpiProfile::query()->withoutGlobalScopes()->where('ministry_id', $profile->ministry_id)->select('id'))
                ->delete();

            $existingMissionIds = $profile->kpiProfileMissions()->pluck('mission_id')->all();

            foreach (array_diff($missionIds, $existingMissionIds) as $missionId) {
                KpiProfileMission::create([
                    'kpi_profile_id' => $profile->id,
                    'mission_id' => $missionId,
                    'assigned_by_user_id' => $admin->id,
                ]);
            }
        });
    }

    // --- Periods (FR-KPI-009, 016) ---------------------------------------

    /**
     * FR-KPI-016: the dashboard opens on a half-yearly view — the cycle
     * holding the most recently settled quarter (one whose reporting
     * deadline has passed), so there is always settled data to judge.
     */
    public function defaultPeriod(?CarbonInterface $today = null): KpiPeriod
    {
        $today ??= $this->performance->today();
        $quarter = KpiPeriod::quarterContaining($today);

        while (! $quarter->isFinal($today)) {
            $quarter = $quarter->previous();
        }

        return KpiPeriod::halfContaining($quarter->start());
    }

    /**
     * A quarter or half-year label, or a custom from/to range of whole
     * quarters (FR-KPI-009). A range may not run past the current quarter.
     */
    public function resolvePeriod(?string $label, ?string $from = null, ?string $to = null): KpiPeriod
    {
        $today = $this->performance->today();

        if (($from ?? '') !== '' || ($to ?? '') !== '') {
            if (($from ?? '') === '' || ($to ?? '') === '') {
                throw new InvalidArgumentException('A custom range needs both a start and an end date.');
            }

            try {
                $start = Carbon::parse($from);
                $end = Carbon::parse($to);
            } catch (\Throwable) {
                throw new InvalidArgumentException('The custom range dates are not valid dates.');
            }

            if ($end->toDateString() > KpiPeriod::quarterContaining($today)->end()->toDateString()) {
                throw new InvalidArgumentException('A custom range cannot extend beyond the current quarter.');
            }

            return KpiPeriod::between($start, $end, (int) config('kpi.max_range_quarters', 12));
        }

        if (($label ?? '') === '') {
            return $this->defaultPeriod($today);
        }

        return KpiPeriod::fromLabel($label);
    }

    /**
     * The periods a KPI screen offers: the current quarter and the seven
     * before it, the current half-year and the four before it.
     *
     * @return array{default: string, quarters: array<int, array<string, mixed>>, halves: array<int, array<string, mixed>>, range: array{max_quarters: int, latest_end: string}}
     */
    public function periodOptions(?CarbonInterface $today = null): array
    {
        $today ??= $this->performance->today();
        $quarters = [];
        for ($quarter = KpiPeriod::quarterContaining($today), $index = 0; $index < 8; $index++, $quarter = $quarter->previous()) {
            $quarters[] = $this->periodRef($quarter, $today);
        }

        $halves = [];
        for ($half = KpiPeriod::halfContaining($today), $index = 0; $index < 5; $index++, $half = $half->previous()) {
            $halves[] = $this->periodRef($half, $today);
        }

        return [
            'default' => $this->defaultPeriod($today)->label,
            'quarters' => $quarters,
            'halves' => $halves,
            'range' => [
                'max_quarters' => (int) config('kpi.max_range_quarters', 12),
                'latest_end' => KpiPeriod::quarterContaining($today)->end()->toDateString(),
            ],
        ];
    }

    /**
     * The missions a KPI screen can show: the department's active postings.
     *
     * @return Collection<int, Mission>
     */
    public function missionOptions(string $ministryId): Collection
    {
        return $this->performance->missions($ministryId);
    }

    // --- Targets (FR-KPI-003, 004, 005; BR-019) ---------------------------

    /**
     * BR-019, FR-KPI-004, FR-KPI-005: targets are versioned by performance
     * cycle. Setting a target NEVER updates an earlier row — it inserts a
     * new one, and the newest row for a mission, KPI and cycle is the one
     * in force. Callers validate the cycle first (assertCycleEditable()).
     */
    public function setTarget(
        KpiDefinition $kpi,
        Mission $mission,
        string $cycleLabel,
        Carbon $cycleStart,
        float $targetValue,
        User $setter,
        ?string $note = null,
    ): KpiTarget {
        // KpiTarget is registered on ModelObserver, so this create() is
        // audit-logged with the setting user and timestamp (FR-KPI-005 AC1).
        return KpiTarget::create([
            'mission_id' => $mission->id,
            'kpi_definition_id' => $kpi->id,
            'performance_cycle_label' => $cycleLabel,
            'cycle_start_date' => $cycleStart,
            'target_value' => $targetValue,
            'note' => $note,
            'set_by_user_id' => $setter->id,
        ]);
    }

    /**
     * FR-KPI-003: a profile's default target for one KPI and cycle, used by
     * every mission on the profile without its own override. Versioned the
     * same way as setTarget().
     */
    public function setProfileTarget(
        KpiDefinition $kpi,
        KpiProfile $profile,
        string $cycleLabel,
        Carbon $cycleStart,
        float $targetValue,
        User $setter,
        ?string $note = null,
    ): KpiProfileTarget {
        return KpiProfileTarget::create([
            'kpi_profile_id' => $profile->id,
            'kpi_definition_id' => $kpi->id,
            'performance_cycle_label' => $cycleLabel,
            'cycle_start_date' => $cycleStart,
            'target_value' => $targetValue,
            'note' => $note,
            'set_by_user_id' => $setter->id,
        ]);
    }

    /**
     * Targets are set per half-yearly performance cycle (CLAUDE.md Section
     * 8), from the current cycle up to the configured horizon. A cycle that
     * has ended is closed: its targets are the historical record (FR-KPI-004)
     * and a late revision would rewrite what it was judged against.
     *
     * @return array{editable: bool, reason: ?string}
     */
    public function cycleEditability(KpiPeriod $cycle, ?CarbonInterface $today = null): array
    {
        $today ??= $this->performance->today();
        $horizon = KpiPeriod::halfContaining($today);
        for ($step = 0; $step < (int) config('kpi.target_horizon_cycles', 3); $step++) {
            $horizon = $horizon->next();
        }

        return match (true) {
            $cycle->type !== KpiPeriod::HALF => ['editable' => false, 'reason' => 'not_a_cycle'],
            $cycle->phase($today) === KpiPeriod::COMPLETE => ['editable' => false, 'reason' => 'ended'],
            $cycle->start()->greaterThan($horizon->start()) => ['editable' => false, 'reason' => 'beyond_horizon'],
            default => ['editable' => true, 'reason' => null],
        };
    }

    public function assertCycleEditable(KpiPeriod $cycle): void
    {
        $reason = $this->cycleEditability($cycle)['reason'];

        if ($reason !== null) {
            throw new InvalidArgumentException(match ($reason) {
                'not_a_cycle' => 'Targets are set for a half-yearly performance cycle, such as H1 2026.',
                'ended' => "{$cycle->label} has ended; its targets are kept as the historical record and can no longer be changed.",
                default => 'Targets can be set at most '.((int) config('kpi.target_horizon_cycles', 3))." cycles ahead; {$cycle->label} is too far out.",
            });
        }
    }

    /**
     * The cycles the target planner offers: the two before the current one
     * (read-only history), the current one, and the cycles up to the horizon.
     *
     * @return array<int, array<string, mixed>>
     */
    public function targetCycleOptions(?CarbonInterface $today = null): array
    {
        $today ??= $this->performance->today();
        $cycle = KpiPeriod::halfContaining($today)->previous()->previous();
        $options = [];

        for ($index = 0; $index < 3 + (int) config('kpi.target_horizon_cycles', 3); $index++, $cycle = $cycle->next()) {
            $options[] = [...$this->periodRef($cycle, $today), ...$this->cycleEditability($cycle, $today)];
        }

        return $options;
    }

    /**
     * The Set KPI Targets screen: every mission x KPI of the department for
     * one cycle — the target in force and where it comes from (mission
     * override or profile default), each profile's defaults, the previous
     * cycle's target and result alongside, and how complete the plan is.
     *
     * @return array<string, mixed>
     */
    public function targetPlan(string $ministryId, KpiPeriod $cycle): array
    {
        $today = $this->performance->today();
        $definitions = $this->performance->definitions($ministryId);
        $missions = $this->performance->missions($ministryId);
        $applicability = $this->performance->applicability($ministryId, $missions, $definitions);
        $kpiIds = $definitions->pluck('id')->all();
        $missionIds = $missions->pluck('id')->all();

        $overrides = $this->performance->latestOverrides($missionIds, $kpiIds, [$cycle->label]);
        $profiles = KpiProfile::query()
            ->withoutGlobalScopes()
            ->where('ministry_id', $ministryId)
            ->with([
                'kpiProfileDefinitions' => fn ($query) => $query->withoutGlobalScopes(),
                'kpiProfileMissions' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->orderBy('name')
            ->get();
        $defaults = $this->performance->latestProfileDefaults($profiles->pluck('id')->all(), $kpiIds, [$cycle->label]);
        $profileNames = $profiles->pluck('name', 'id');

        $overrideVersions = KpiTarget::query()->withoutGlobalScopes()
            ->whereIn('mission_id', $missionIds)->whereIn('kpi_definition_id', $kpiIds)
            ->where('performance_cycle_label', $cycle->label)
            ->selectRaw('mission_id, kpi_definition_id, count(*) as versions')
            ->groupBy('mission_id', 'kpi_definition_id')
            ->toBase()->get()
            ->mapWithKeys(fn (object $row): array => ["{$row->mission_id}|{$row->kpi_definition_id}" => (int) $row->versions]);
        $defaultVersions = KpiProfileTarget::query()->withoutGlobalScopes()
            ->whereIn('kpi_profile_id', $profiles->pluck('id'))->whereIn('kpi_definition_id', $kpiIds)
            ->where('performance_cycle_label', $cycle->label)
            ->selectRaw('kpi_profile_id, kpi_definition_id, count(*) as versions')
            ->groupBy('kpi_profile_id', 'kpi_definition_id')
            ->toBase()->get()
            ->mapWithKeys(fn (object $row): array => ["{$row->kpi_profile_id}|{$row->kpi_definition_id}" => (int) $row->versions]);

        $previousCycle = $cycle->previous();
        $previousCells = $this->performance->evaluate($ministryId, $missions, $definitions, $applicability, $previousCycle, $today);
        $attaches = $this->postedAttaches($ministryId);

        $summary = ['required' => 0, 'set' => 0, 'missing' => 0, 'overrides' => 0, 'from_profile' => 0, 'missions_complete' => 0];

        $missionRows = $missions->map(function (Mission $mission) use ($definitions, $applicability, $cycle, $overrides, $defaults, $profileNames, $overrideVersions, $previousCells, $attaches, &$summary): array {
            $entry = $applicability[$mission->id];
            $cells = [];
            $missionMissing = 0;

            foreach ($definitions as $kpi) {
                $applicable = in_array($kpi->id, $entry['kpi_ids'], true);
                $override = $overrides["{$mission->id}|{$kpi->id}|{$cycle->label}"] ?? null;
                $default = null;
                $defaultProfileId = null;
                foreach ($entry['profile_ids_by_kpi'][$kpi->id] ?? [] as $profileId) {
                    if (isset($defaults["{$profileId}|{$kpi->id}|{$cycle->label}"])) {
                        $default = $defaults["{$profileId}|{$kpi->id}|{$cycle->label}"];
                        $defaultProfileId = $profileId;
                        break;
                    }
                }

                $effective = $override ?? $default;
                if ($applicable) {
                    $summary['required']++;
                    if ($effective !== null) {
                        $summary['set']++;
                        $summary[$override !== null ? 'overrides' : 'from_profile']++;
                    } else {
                        $summary['missing']++;
                        $missionMissing++;
                    }
                }

                $previous = $previousCells[$mission->id][$kpi->id];
                $cells[$kpi->id] = [
                    'applicable' => $applicable,
                    'value' => $applicable && $effective !== null ? (float) $effective->target_value : null,
                    'source' => $applicable && $effective !== null ? ($override !== null ? 'mission' : 'profile') : null,
                    'override' => $override !== null ? [
                        ...$this->targetVersionRef($override),
                        'versions' => $overrideVersions["{$mission->id}|{$kpi->id}"] ?? 1,
                    ] : null,
                    'profile_default' => $default !== null ? [
                        'value' => (float) $default->target_value,
                        'profile' => ['id' => $defaultProfileId, 'name' => $profileNames[$defaultProfileId] ?? null],
                    ] : null,
                    'previous' => [
                        'target' => $previous['target'],
                        'actual' => $previous['actual'],
                        'status' => $previous['status'],
                    ],
                ];
            }

            if ($missionMissing === 0) {
                $summary['missions_complete']++;
            }

            return [
                'id' => $mission->id,
                'name' => $mission->name,
                'city' => $mission->city,
                'host_country' => $mission->host_country,
                'attache' => $attaches[$mission->id] ?? null,
                'profiles' => $entry['profiles'],
                'missing' => $missionMissing,
                'targets' => $cells,
            ];
        })->values()->all();

        $activeMissionIds = $missions->pluck('id')->all();

        return [
            'cycle' => [...$this->periodRef($cycle, $today), ...$this->cycleEditability($cycle, $today)],
            'previous_cycle' => $this->periodRef($previousCycle, $today),
            'cycles' => $this->targetCycleOptions($today),
            'thresholds' => $this->performance->thresholds(),
            'kpis' => $definitions->map(fn (KpiDefinition $kpi): array => $this->kpiMeta($kpi))->values()->all(),
            'profiles' => $profiles->map(fn (KpiProfile $profile): array => [
                'id' => $profile->id,
                'name' => $profile->name,
                'kpi_ids' => $profile->kpiProfileDefinitions->pluck('kpi_definition_id')->intersect($definitions->pluck('id'))->values()->all(),
                'mission_ids' => $profile->kpiProfileMissions->pluck('mission_id')->intersect($activeMissionIds)->values()->all(),
                'defaults' => $definitions
                    ->mapWithKeys(function (KpiDefinition $kpi) use ($profile, $defaults, $defaultVersions, $cycle): array {
                        $default = $defaults["{$profile->id}|{$kpi->id}|{$cycle->label}"] ?? null;

                        return $default === null ? [] : [$kpi->id => [
                            ...$this->targetVersionRef($default),
                            'versions' => $defaultVersions["{$profile->id}|{$kpi->id}"] ?? 1,
                        ]];
                    })
                    ->all(),
            ])->values()->all(),
            'missions' => $missionRows,
            'summary' => $summary,
        ];
    }

    /**
     * One target, checked like a planner entry but always stored as a new
     * version (POST /kpi-targets, BR-019): a mission override for a mission
     * that tracks the KPI, or a default for a profile that includes it.
     */
    public function setTargetFor(string $ministryId, KpiDefinition $kpi, ?Mission $mission, ?KpiProfile $profile, KpiPeriod $cycle, float $value, User $setter, ?string $note = null): KpiTarget|KpiProfileTarget
    {
        $this->assertCycleEditable($cycle);

        if (! $kpi->active || $kpi->ministry_id !== $ministryId) {
            throw new InvalidArgumentException("\"{$kpi->name}\" is not an active KPI of your department.");
        }

        if ($mission !== null) {
            $missions = $this->performance->missions($ministryId);
            if (! $missions->contains('id', $mission->id)) {
                throw new InvalidArgumentException("{$mission->name} is not an active posting of your department.");
            }

            $applicability = $this->performance->applicability($ministryId, collect([$mission]), $this->performance->definitions($ministryId));
            if (! in_array($kpi->id, $applicability[$mission->id]['kpi_ids'], true)) {
                throw new InvalidArgumentException("{$mission->name} does not track \"{$kpi->name}\" under its KPI profile.");
            }

            return $this->setTarget($kpi, $mission, $cycle->label, Carbon::instance($cycle->start()), round($value, 2), $setter, $note);
        }

        if ($profile === null || $profile->ministry_id !== $ministryId) {
            throw new InvalidArgumentException('That KPI profile does not belong to your department.');
        }

        if (! $profile->kpiProfileDefinitions()->withoutGlobalScopes()->where('kpi_definition_id', $kpi->id)->exists()) {
            throw new InvalidArgumentException("The \"{$profile->name}\" profile does not include \"{$kpi->name}\".");
        }

        return $this->setProfileTarget($kpi, $profile, $cycle->label, Carbon::instance($cycle->start()), round($value, 2), $setter, $note);
    }

    /**
     * Saves a batch of targets for one cycle from the target planner. Each
     * entry targets a mission (override) or a profile (default). An entry
     * equal to the version already in force is skipped, so saving the grid
     * twice creates no extra versions; every other entry inserts a new
     * version (BR-019). All or nothing.
     *
     * @param  array<int, array{kpi_definition_id: string, mission_id?: ?string, kpi_profile_id?: ?string, target_value: float|int|string}>  $entries
     * @return array{saved: int, unchanged: int}
     */
    public function saveTargets(string $ministryId, KpiPeriod $cycle, array $entries, User $setter, ?string $note = null): array
    {
        $this->assertCycleEditable($cycle);

        $definitions = $this->performance->definitions($ministryId)->keyBy('id');
        $missions = $this->performance->missions($ministryId)->keyBy('id');
        $applicability = $this->performance->applicability($ministryId, $missions->values(), $definitions->values());
        $profiles = KpiProfile::query()
            ->withoutGlobalScopes()
            ->where('ministry_id', $ministryId)
            ->with(['kpiProfileDefinitions' => fn ($query) => $query->withoutGlobalScopes()])
            ->get()
            ->keyBy('id');

        $overrides = $this->performance->latestOverrides($missions->keys()->all(), $definitions->keys()->all(), [$cycle->label]);
        $defaults = $this->performance->latestProfileDefaults($profiles->keys()->all(), $definitions->keys()->all(), [$cycle->label]);

        $saved = 0;
        $unchanged = 0;

        DB::transaction(function () use ($entries, $definitions, $missions, $applicability, $profiles, $overrides, $defaults, $cycle, $setter, $note, &$saved, &$unchanged): void {
            foreach ($entries as $index => $entry) {
                $kpi = $definitions->get($entry['kpi_definition_id']) ?? throw new InvalidArgumentException('Target '.($index + 1).': that KPI is not an active KPI of your department.');
                $value = round((float) $entry['target_value'], 2);

                if (! empty($entry['mission_id'])) {
                    $mission = $missions->get($entry['mission_id']) ?? throw new InvalidArgumentException('Target '.($index + 1).': that mission is not an active posting of your department.');
                    if (! in_array($kpi->id, $applicability[$mission->id]['kpi_ids'], true)) {
                        throw new InvalidArgumentException("{$mission->name} does not track \"{$kpi->name}\" under its KPI profile.");
                    }

                    $current = $overrides["{$mission->id}|{$kpi->id}|{$cycle->label}"] ?? null;
                    if ($current !== null && round((float) $current->target_value, 2) === $value) {
                        $unchanged++;

                        continue;
                    }

                    $this->setTarget($kpi, $mission, $cycle->label, Carbon::instance($cycle->start()), $value, $setter, $note);
                    $saved++;

                    continue;
                }

                $profile = $profiles->get($entry['kpi_profile_id'] ?? '') ?? throw new InvalidArgumentException('Target '.($index + 1).': that KPI profile does not belong to your department.');
                if (! $profile->kpiProfileDefinitions->contains('kpi_definition_id', $kpi->id)) {
                    throw new InvalidArgumentException("The \"{$profile->name}\" profile does not include \"{$kpi->name}\".");
                }

                $current = $defaults["{$profile->id}|{$kpi->id}|{$cycle->label}"] ?? null;
                if ($current !== null && round((float) $current->target_value, 2) === $value) {
                    $unchanged++;

                    continue;
                }

                $this->setProfileTarget($kpi, $profile, $cycle->label, Carbon::instance($cycle->start()), $value, $setter, $note);
                $saved++;
            }
        });

        return ['saved' => $saved, 'unchanged' => $unchanged];
    }

    /**
     * FR-KPI-004 AC1: every version of a mission's (or a profile's) target
     * for one KPI, across all cycles, newest first, marking the version in
     * force for each cycle.
     *
     * @return array<int, array<string, mixed>>
     */
    public function targetHistory(KpiDefinition $kpi, ?Mission $mission, ?KpiProfile $profile): array
    {
        $query = $mission !== null
            ? KpiTarget::query()->withoutGlobalScopes()->where('mission_id', $mission->id)
            : KpiProfileTarget::query()->withoutGlobalScopes()->where('kpi_profile_id', $profile?->id);

        $versions = $query
            ->where('kpi_definition_id', $kpi->id)
            ->with('setBy')
            ->orderByDesc('cycle_start_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $seenCycles = [];

        return $versions->map(function (KpiTarget|KpiProfileTarget $version) use (&$seenCycles): array {
            $inForce = ! isset($seenCycles[$version->performance_cycle_label]);
            $seenCycles[$version->performance_cycle_label] = true;

            return [
                'id' => $version->id,
                'cycle_label' => $version->performance_cycle_label,
                'cycle_start_date' => $version->cycle_start_date?->toDateString(),
                ...$this->targetVersionRef($version),
                'in_force' => $inForce,
            ];
        })->values()->all();
    }

    // --- Actuals (FR-KPI-006, 007) ----------------------------------------

    /**
     * FR-KPI-006, FR-KPI-007: records (or, for a mission/KPI/quarter already
     * recorded, updates in place) the actual value for one quarter. Used by
     * manual entry (calculation_type 'manual', attributed to the entering
     * user) and by foams:compute-kpi-actuals ('auto', no user — CLAUDE.md
     * Section 6: entered_by_user_id is null for auto rows).
     */
    public function recordActual(
        KpiDefinition $kpi,
        Mission $mission,
        string $periodLabel,
        Carbon $periodStart,
        float $value,
        ?User $actor,
        string $calculationType,
    ): KpiActual {
        return KpiActual::withoutGlobalScopes()->updateOrCreate(
            [
                'mission_id' => $mission->id,
                'kpi_definition_id' => $kpi->id,
                'period_start_date' => $periodStart->toDateString(),
            ],
            [
                'period_label' => $periodLabel,
                'actual_value' => $value,
                'entered_by_user_id' => $actor?->id,
                'calculation_type' => $calculationType,
            ],
        );
    }

    /**
     * Rules for a manually entered actual: the KPI is one the engine cannot
     * count itself, the mission is a posting of the department that tracks
     * the KPI, and the quarter has begun and is not closed.
     */
    public function assertActualRecordable(string $ministryId, KpiDefinition $kpi, Mission $mission, KpiPeriod $quarter): void
    {
        if (KpiDataSources::isLive($kpi)) {
            throw new InvalidArgumentException("\"{$kpi->name}\" is calculated automatically from live data and cannot be entered by hand.");
        }

        if (! $kpi->active) {
            throw new InvalidArgumentException("\"{$kpi->name}\" is no longer an active KPI.");
        }

        $missions = $this->performance->missions($ministryId);
        if (! $missions->contains('id', $mission->id)) {
            throw new InvalidArgumentException("{$mission->name} is not an active posting of your department.");
        }

        $applicability = $this->performance->applicability($ministryId, collect([$mission]), $this->performance->definitions($ministryId));
        if (! in_array($kpi->id, $applicability[$mission->id]['kpi_ids'], true)) {
            throw new InvalidArgumentException("{$mission->name} does not track \"{$kpi->name}\" under its KPI profile.");
        }

        if ($quarter->type !== KpiPeriod::QUARTER) {
            throw new InvalidArgumentException('Actuals are recorded per quarter, such as Q1 2026.');
        }

        $today = $this->performance->today();
        if ($quarter->phase($today) === KpiPeriod::UPCOMING) {
            throw new InvalidArgumentException("{$quarter->label} has not started yet.");
        }

        $oldest = KpiPeriod::quarterContaining($today);
        for ($step = 0; $step < self::ACTUAL_ENTRY_QUARTERS_BACK; $step++) {
            $oldest = $oldest->previous();
        }

        if ($quarter->start()->lessThan($oldest->start())) {
            throw new InvalidArgumentException("{$quarter->label} is closed for entry; actuals can be recorded for the current quarter and the ".self::ACTUAL_ENTRY_QUARTERS_BACK.' before it.');
        }
    }

    /**
     * The manual entry screen: the quarters open for entry, and for one
     * mission and quarter every KPI it tracks — recordable ones with their
     * current value and quarter target, live ones with their calculated
     * value (read-only).
     *
     * @return array<string, mixed>
     */
    public function actualEntryContext(string $ministryId, Mission $mission, KpiPeriod $quarter): array
    {
        $today = $this->performance->today();
        $definitions = $this->performance->definitions($ministryId);
        $missions = collect([$mission]);
        $applicability = $this->performance->applicability($ministryId, $missions, $definitions);
        $cells = $this->performance->evaluate($ministryId, $missions, $definitions, $applicability, $quarter, $today)[$mission->id];

        $stored = KpiActual::query()
            ->withoutGlobalScopes()
            ->with('enteredBy')
            ->where('mission_id', $mission->id)
            ->where('period_start_date', $quarter->start()->toDateString())
            ->get()
            ->keyBy('kpi_definition_id');

        $quarters = [];
        for ($option = KpiPeriod::quarterContaining($today), $index = 0; $index <= self::ACTUAL_ENTRY_QUARTERS_BACK; $index++, $option = $option->previous()) {
            $quarters[] = $this->periodRef($option, $today);
        }

        return [
            'quarter' => $this->periodRef($quarter, $today),
            'quarters' => $quarters,
            'mission' => ['id' => $mission->id, 'name' => $mission->name, 'city' => $mission->city, 'host_country' => $mission->host_country],
            'kpis' => $definitions
                ->filter(fn (KpiDefinition $kpi): bool => in_array($kpi->id, $applicability[$mission->id]['kpi_ids'], true))
                ->map(function (KpiDefinition $kpi) use ($cells, $stored): array {
                    $actual = $stored->get($kpi->id);

                    return [
                        ...$this->kpiMeta($kpi),
                        'recordable' => ! KpiDataSources::isLive($kpi),
                        'value' => $cells[$kpi->id]['actual'],
                        'quarter_target' => $cells[$kpi->id]['target'],
                        'entered_by' => $actual?->enteredBy ? ['id' => $actual->enteredBy->id, 'full_name' => $actual->enteredBy->full_name] : null,
                        'updated_at' => $actual?->updated_at?->toIso8601String(),
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * FR-KPI-016: aggregates Q1+Q2 (Jul-Dec) into H1 and Q3+Q4 (Jan-Jun)
     * into H2 for the labelled $year, keyed on period_start_date — a value
     * recorded late for a quarter still belongs to that quarter
     * (TC-FR-KPI-016-B).
     *
     * @return array{H1: array<string, mixed>, H2: array<string, mixed>}
     */
    public function computeAggregation(KpiDefinition $kpi, Mission $mission, int $year): array
    {
        $aggregate = function (KpiPeriod $half) use ($kpi, $mission): array {
            $values = $this->performance->quarterlyActuals($kpi->ministry_id, collect([$mission]), collect([$kpi]), $half->start(), $half->end());
            $quarters = [];
            foreach ($half->quarters as $quarter) {
                $quarters[$quarter['label']] = $values[$mission->id][$kpi->id][$quarter['start']->toDateString()] ?? null;
            }
            $reported = array_filter($quarters, fn (?float $value): bool => $value !== null);

            return [
                'label' => $half->label,
                'quarters' => $quarters,
                'total' => $reported === [] ? null : (float) array_sum($reported),
                'quarters_reported' => count($reported),
            ];
        };

        return [
            'H1' => $aggregate(KpiPeriod::half($year, 1)),
            'H2' => $aggregate(KpiPeriod::half($year, 2)),
        ];
    }

    // --- Read models (FR-KPI-008 to 016) ------------------------------------

    /**
     * The KPI dashboard (FR-KPI-008/009/010/016). With a mission: each KPI
     * it tracks with target, actual, status, the previous period, a quarterly
     * trend and the mission's report compliance (FR-KPI-012). Without one:
     * the department total for each KPI, how its missions are spread across
     * the statuses, and the missions ranked weakest first.
     *
     * @return array<string, mixed>
     */
    public function dashboard(string $ministryId, ?Mission $mission, KpiPeriod $period, bool $withReportLinks = true): array
    {
        $today = $this->performance->today();
        $definitions = $this->performance->definitions($ministryId);
        $missions = $mission !== null ? collect([$mission]) : $this->performance->missions($ministryId);
        $applicability = $this->performance->applicability($ministryId, $missions, $definitions);

        $previousPeriod = $period->previous();
        $trendPeriod = $this->trendPeriod($period, $today);
        $cells = $this->performance->evaluate($ministryId, $missions, $definitions, $applicability, $period, $today);
        $previousCells = $this->performance->evaluate($ministryId, $missions, $definitions, $applicability, $previousPeriod, $today);
        $trendCells = $this->performance->evaluate($ministryId, $missions, $definitions, $applicability, $trendPeriod, $today);

        $base = [
            'scope' => $mission !== null ? 'mission' : 'ministry',
            'period' => $period->toArray($today),
            'previous_period' => $this->periodRef($previousPeriod, $today),
            'thresholds' => $this->performance->thresholds(),
        ];

        if ($mission !== null) {
            $missionCells = $cells[$mission->id];
            $kpis = $definitions
                ->filter(fn (KpiDefinition $kpi): bool => $missionCells[$kpi->id]['applicable'])
                ->map(fn (KpiDefinition $kpi): array => $this->dashboardRow(
                    $kpi,
                    $missionCells[$kpi->id],
                    $previousCells[$mission->id][$kpi->id],
                    $trendCells[$mission->id][$kpi->id]['quarters'],
                ))
                ->values()
                ->all();

            return [
                ...$base,
                'mission' => [
                    'id' => $mission->id,
                    'name' => $mission->name,
                    'city' => $mission->city,
                    'host_country' => $mission->host_country,
                    'attache' => $this->postedAttaches($ministryId)[$mission->id] ?? null,
                    'profiles' => $applicability[$mission->id]['profiles'],
                ],
                'summary' => $this->performance->summarise(array_values($missionCells)),
                'kpis' => $kpis,
                'compliance' => $this->reportCompliance($ministryId, $mission, $period, $today, $withReportLinks),
            ];
        }

        $kpis = $definitions
            ->map(function (KpiDefinition $kpi) use ($missions, $cells, $previousCells, $trendCells, $period, $previousPeriod, $trendPeriod, $today): ?array {
                $column = $missions->map(fn (Mission $row): array => $cells[$row->id][$kpi->id])->all();
                if (! collect($column)->contains('applicable', true)) {
                    return null;
                }
                $total = $this->performance->combine($column, $period, $today);
                $previousTotal = $this->performance->combine($missions->map(fn (Mission $row): array => $previousCells[$row->id][$kpi->id])->all(), $previousPeriod, $today);
                $trendTotal = $this->performance->combine($missions->map(fn (Mission $row): array => $trendCells[$row->id][$kpi->id])->all(), $trendPeriod, $today);

                return $this->dashboardRow($kpi, $total, $previousTotal, $trendTotal['quarters']);
            })
            ->filter()
            ->values()
            ->all();

        $allCells = collect($cells)->flatMap(fn (array $missionCells): array => array_values($missionCells))->all();

        return [
            ...$base,
            'mission' => null,
            'summary' => $this->performance->summarise($allCells),
            'kpis' => $kpis,
            'missions' => $missions
                ->map(fn (Mission $row): array => [
                    'mission_id' => $row->id,
                    'mission_name' => $row->name,
                    'city' => $row->city,
                    'host_country' => $row->host_country,
                    ...$this->performance->summarise(array_values($cells[$row->id])),
                ])
                ->sortBy(fn (array $row): float => $row['score'] ?? 2.0)
                ->values()
                ->all(),
            'compliance' => null,
        ];
    }

    /**
     * FR-KPI-013/014/SDT-011: every mission against every KPI for one period
     * (quarterly or half-yearly, or a custom range), with the department
     * total per KPI and each mission's summary. Ranking is done by the
     * client on any column; the cells carry everything a ranking needs.
     * Keeps the {cycle_label, missions[].kpis[]} shape the HRM&D views read.
     *
     * @return array<string, mixed>
     */
    public function comparison(string $ministryId, KpiPeriod $period): array
    {
        $today = $this->performance->today();
        $definitions = $this->performance->definitions($ministryId);
        $missions = $this->performance->missions($ministryId);
        $applicability = $this->performance->applicability($ministryId, $missions, $definitions);
        $previousPeriod = $period->previous();
        $cells = $this->performance->evaluate($ministryId, $missions, $definitions, $applicability, $period, $today);
        $previousCells = $this->performance->evaluate($ministryId, $missions, $definitions, $applicability, $previousPeriod, $today);

        return [
            'cycle_label' => $period->label,
            'period' => $period->toArray($today),
            'previous_period' => $this->periodRef($previousPeriod, $today),
            'thresholds' => $this->performance->thresholds(),
            'kpis' => $definitions->map(function (KpiDefinition $kpi) use ($missions, $cells, $previousCells, $period, $previousPeriod, $today): array {
                $total = $this->performance->combine($missions->map(fn (Mission $mission): array => $cells[$mission->id][$kpi->id])->all(), $period, $today);
                $previousTotal = $this->performance->combine($missions->map(fn (Mission $mission): array => $previousCells[$mission->id][$kpi->id])->all(), $previousPeriod, $today);

                return [
                    ...$this->kpiMeta($kpi),
                    'total' => [...$total, 'previous_actual' => $previousTotal['actual']],
                ];
            })->values()->all(),
            'missions' => $missions->map(function (Mission $mission) use ($definitions, $cells, $previousCells, $applicability): array {
                return [
                    'mission_id' => $mission->id,
                    'mission_name' => $mission->name,
                    'city' => $mission->city,
                    'host_country' => $mission->host_country,
                    'profiles' => $applicability[$mission->id]['profiles'],
                    'summary' => $this->performance->summarise(array_values($cells[$mission->id])),
                    'kpis' => $definitions->map(function (KpiDefinition $kpi) use ($mission, $cells, $previousCells): array {
                        $cell = $cells[$mission->id][$kpi->id];
                        $previous = $previousCells[$mission->id][$kpi->id];

                        return [
                            'kpi_definition_id' => $kpi->id,
                            'name' => $kpi->name,
                            ...$cell,
                            ...$this->changeFrom($cell['actual'], $previous['actual']),
                            'previous_status' => $previous['status'],
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
            'summary' => $this->performance->summarise(collect($cells)->flatMap(fn (array $row): array => array_values($row))->all()),
        ];
    }

    /**
     * FR-KPI-013 by cycle label — the comparison() shape, kept for the
     * HRM&D dashboard and existing callers.
     *
     * @return array<string, mixed>
     */
    public function buildComparisonMatrix(string $ministryId, string $cycleLabel): array
    {
        return $this->comparison($ministryId, KpiPeriod::fromLabel($cycleLabel));
    }

    /**
     * FR-KPI-015: targets, actuals, variance, the previous period (trend)
     * and each quarter's report compliance for one mission or every mission,
     * attributed to the generating user — the data behind the downloadable
     * report.
     *
     * @return array<string, mixed>
     */
    public function buildMissionReport(string $ministryId, ?string $missionId, string $cycleLabel, User $generatedBy): array
    {
        $period = KpiPeriod::fromLabel($cycleLabel);
        $today = $this->performance->today();
        $comparison = $this->comparison($ministryId, $period);
        $missions = collect($comparison['missions'])->when($missionId !== null, fn (Collection $rows) => $rows->where('mission_id', $missionId));

        if ($missionId !== null && $missions->isEmpty()) {
            throw new InvalidArgumentException('That mission is not an active posting of your department.');
        }

        $missionModels = Mission::query()->whereIn('id', $missions->pluck('mission_id'))->get()->keyBy('id');

        return [
            'ministry_id' => $ministryId,
            'cycle_label' => $period->label,
            'period' => $comparison['period'],
            'previous_period' => $comparison['previous_period'],
            'thresholds' => $comparison['thresholds'],
            'generated_by' => ['id' => $generatedBy->id, 'full_name' => $generatedBy->full_name],
            'generated_at' => now()->toIso8601String(),
            'missions' => $missions
                ->map(fn (array $row): array => [
                    ...$row,
                    'compliance' => $this->reportCompliance($ministryId, $missionModels[$row['mission_id']], $period, $today, false),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * FR-SDT-016, FR-KPI-011: HRM&D read-only comparison across the
     * officer's own department; every access is audit-logged (AC1).
     *
     * @return array<string, mixed>
     */
    public function hrmdDashboard(User $officer, string $cycleLabel): array
    {
        $matrix = $this->buildComparisonMatrix($officer->ministry_id, $cycleLabel);

        $this->auditService->record(
            $officer,
            'kpi.hrmd_dashboard.accessed',
            'Ministry',
            $officer->ministry_id,
            ['cycle_label' => $cycleLabel],
        );

        return $matrix;
    }

    /**
     * FR-SDT-017, FR-KPI-011 AC2: one attache's KPI summary for one period,
     * for HR evaluation; audit-logged. The controller confirms the attache
     * belongs to the officer's department first (User is not ministry-scoped).
     *
     * @return array{attache: array<string, mixed>, cycle_label: string, kpis: array<int, array<string, mixed>>}
     */
    public function attachePerformanceSummary(User $officer, User $attache, string $cycleLabel): array
    {
        if ($attache->mission === null) {
            throw new InvalidArgumentException('The selected user is not assigned to a mission.');
        }

        $summary = [
            'attache' => [
                'id' => $attache->id,
                'full_name' => $attache->full_name,
                'mission' => ['id' => $attache->mission->id, 'name' => $attache->mission->name],
            ],
            'cycle_label' => $cycleLabel,
            'kpis' => $this->missionPerformance($attache->mission, $attache->ministry_id, $cycleLabel),
        ];

        $this->auditService->record(
            $officer,
            'kpi.hrmd_attache_summary.generated',
            'User',
            $attache->id,
            ['cycle_label' => $cycleLabel],
        );

        return $summary;
    }

    /**
     * FR-KPI-010: one mission's KPIs (those it tracks) for one cycle label —
     * read by the attache's home dashboard and the HRM&D summary.
     *
     * @return array<int, array<string, mixed>>
     */
    public function missionPerformance(Mission $mission, string $ministryId, string $cycleLabel): array
    {
        $definitions = $this->performance->definitions($ministryId);
        $missions = collect([$mission]);
        $applicability = $this->performance->applicability($ministryId, $missions, $definitions);
        $cells = $this->performance->evaluate($ministryId, $missions, $definitions, $applicability, KpiPeriod::fromLabel($cycleLabel))[$mission->id];

        return $definitions
            ->filter(fn (KpiDefinition $kpi): bool => $cells[$kpi->id]['applicable'])
            ->map(fn (KpiDefinition $kpi): array => [
                'kpi_definition_id' => $kpi->id,
                'name' => $kpi->name,
                'unit' => $kpi->unit,
                ...$cells[$kpi->id],
            ])
            ->values()
            ->all();
    }

    /**
     * Status counts per mission for one cycle label (leadership dashboard).
     *
     * @return array<int, array{mission_id: string, mission_name: string, counts: array<string, int>, total: int}>
     */
    public function missionStatusSummary(string $ministryId, string $cycleLabel): array
    {
        $definitions = $this->performance->definitions($ministryId);
        $missions = $this->performance->missions($ministryId);
        $applicability = $this->performance->applicability($ministryId, $missions, $definitions);
        $cells = $this->performance->evaluate($ministryId, $missions, $definitions, $applicability, KpiPeriod::fromLabel($cycleLabel));

        return $missions
            ->map(function (Mission $mission) use ($cells): array {
                $summary = $this->performance->summarise(array_values($cells[$mission->id]));

                return [
                    'mission_id' => $mission->id,
                    'mission_name' => $mission->name,
                    'counts' => $summary['counts'],
                    'total' => $summary['total'],
                ];
            })
            ->values()
            ->all();
    }

    // --- Helpers ------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function kpiMeta(KpiDefinition $kpi): array
    {
        return [
            'id' => $kpi->id,
            'name' => $kpi->name,
            'description' => $kpi->description,
            'unit' => $kpi->unit,
            'calculation_method' => $kpi->calculation_method,
            'data_source' => $kpi->data_source,
            'source' => KpiDataSources::isLive($kpi) ? 'live' : 'recorded',
        ];
    }

    /**
     * @param  array<string, mixed>  $cell
     * @param  array<string, mixed>  $previous
     * @param  array<int, array<string, mixed>>  $trend
     * @return array<string, mixed>
     */
    private function dashboardRow(KpiDefinition $kpi, array $cell, array $previous, array $trend): array
    {
        return [
            ...$this->kpiMeta($kpi),
            'kpi_definition_id' => $kpi->id,
            ...$cell,
            'previous' => [
                'target' => $previous['target'],
                'actual' => $previous['actual'],
                'status' => $previous['status'],
                'attainment' => $previous['attainment'],
            ],
            ...$this->changeFrom($cell['actual'], $previous['actual']),
            'trend' => $trend,
        ];
    }

    /**
     * @return array{previous_actual: ?float, change: ?float, change_pct: ?float}
     */
    private function changeFrom(?float $actual, ?float $previousActual): array
    {
        $change = $actual !== null && $previousActual !== null ? round($actual - $previousActual, 2) : null;

        return [
            'previous_actual' => $previousActual,
            'change' => $change,
            'change_pct' => $change !== null && $previousActual > 0 ? round($change / $previousActual, 4) : null,
        ];
    }

    /**
     * Eight quarters ending with the period's last quarter (or the current
     * quarter, for a period not yet started).
     */
    private function trendPeriod(KpiPeriod $period, CarbonInterface $today): KpiPeriod
    {
        $anchor = $period->phase($today) === KpiPeriod::UPCOMING
            ? KpiPeriod::quarterContaining($today)
            : KpiPeriod::quarterContaining($period->end());

        return KpiPeriod::between($anchor->start()->subMonths(3 * (self::TREND_QUARTERS - 1)), $anchor->start(), self::TREND_QUARTERS);
    }

    /**
     * FR-KPI-012/015: each quarter's report status for a mission. A report
     * is linked only once submitted (drafts are private, FR-RPT-017), and
     * not at all for a viewer who cannot open reports (HRM&D, FR-SDT-018).
     *
     * @return array<int, array<string, mixed>>
     */
    private function reportCompliance(string $ministryId, Mission $mission, KpiPeriod $period, CarbonInterface $today, bool $withReportLinks): array
    {
        $reports = PeriodicReport::query()
            ->withoutGlobalScopes()
            ->where('ministry_id', $ministryId)
            ->where('mission_id', $mission->id)
            ->whereIn('period_start_date', $period->quarterStarts())
            ->get()
            ->keyBy(fn (PeriodicReport $report): string => $report->period_start_date->toDateString());

        return array_map(function (array $quarter) use ($reports, $today, $withReportLinks): array {
            $report = $reports->get($quarter['start']->toDateString());
            $deadline = PeriodicReport::deadlineForPeriodEnd(\Illuminate\Support\Carbon::instance($quarter['end']));
            $due = $quarter['end']->toDateString() < $today->toDateString();

            return [
                'label' => $quarter['label'],
                'start' => $quarter['start']->toDateString(),
                'end' => $quarter['end']->toDateString(),
                'deadline' => $deadline->toDateString(),
                'status' => $report !== null ? $report->complianceStatus() : ($due ? PeriodicReport::COMPLIANCE_NOT_STARTED : 'not_due'),
                'is_overdue' => $report !== null ? $report->isOverdue() : ($due && $deadline->lessThan($today)),
                'submitted_at' => $report?->submitted_at?->toIso8601String(),
                'report_id' => $withReportLinks && $report?->isSubmitted() ? $report->id : null,
            ];
        }, $period->quarters);
    }

    /**
     * @return array<string, array{id: string, full_name: string}>
     */
    private function postedAttaches(string $ministryId): array
    {
        return MissionMinistryLink::query()
            ->where('ministry_id', $ministryId)
            ->whereNotNull('active_attache_user_id')
            ->with('activeAttache')
            ->get()
            ->filter(fn (MissionMinistryLink $link): bool => $link->activeAttache !== null)
            ->mapWithKeys(fn (MissionMinistryLink $link): array => [$link->mission_id => ['id' => $link->activeAttache->id, 'full_name' => $link->activeAttache->full_name]])
            ->all();
    }

    /**
     * @return array{value: float, note: ?string, set_by: ?array{id: string, full_name: string}, set_at: ?string}
     */
    private function targetVersionRef(KpiTarget|KpiProfileTarget $version): array
    {
        return [
            'value' => (float) $version->target_value,
            'note' => $version->note,
            'set_by' => $version->setBy ? ['id' => $version->setBy->id, 'full_name' => $version->setBy->full_name] : null,
            'set_at' => $version->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{label: string, type: string, start: string, end: string, phase: string}
     */
    private function periodRef(KpiPeriod $period, CarbonInterface $today): array
    {
        return [
            'label' => $period->label,
            'type' => $period->type,
            'start' => $period->start()->toDateString(),
            'end' => $period->end()->toDateString(),
            'phase' => $period->phase($today),
        ];
    }
}
