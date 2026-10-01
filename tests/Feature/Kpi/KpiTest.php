<?php

use App\Models\Alert;
use App\Models\KpiActual;
use App\Models\KpiDefinition;
use App\Models\KpiTarget;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\Role;
use App\Models\User;
use App\Services\KpiService;
use App\Services\ReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * CLAUDE.md Section 11, 14 / API-001 (KPI Framework Engine), FR-KPI-001 to
 * 016, FR-SDT-016 to 018, BR-019.
 */
beforeEach(function () {
    // 15 October 2026: H1 2026 (Jul–Dec 2026) is the current performance
    // cycle and Q1 2026 (Jul–Sep 2026) the last finished quarter.
    Carbon::setTestNow('2026-10-15');
});

afterEach(function () {
    Carbon::setTestNow();
});

function kpiRole(string $name, string $layer = '2', string $scope = 'ministry'): Role
{
    return Role::query()->firstOrCreate(['name' => $name], ['layer' => $layer, 'scope' => $scope]);
}

function kpiSystemAdmin(): User
{
    return User::factory()->create([
        'role_id' => kpiRole('System Administrator', '1', 'platform')->id,
    ]);
}

function kpiMinistryHqDirector(Ministry $ministry): User
{
    return User::factory()->create([
        'role_id' => kpiRole('Ministry HQ Director')->id,
        'ministry_id' => $ministry->id,
    ]);
}

function kpiMinistryPs(Ministry $ministry): User
{
    return User::factory()->create([
        'role_id' => kpiRole('Ministry PS', '2/3')->id,
        'ministry_id' => $ministry->id,
    ]);
}

function kpiMinistryAttache(Ministry $ministry, Mission $mission): User
{
    return User::factory()->create([
        'role_id' => kpiRole('Ministry Attache', '2', 'mission')->id,
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
    ]);
}

function kpiMinistryHqOfficer(Ministry $ministry): User
{
    return User::factory()->create([
        'role_id' => kpiRole('Ministry HQ Officer')->id,
        'ministry_id' => $ministry->id,
    ]);
}

function kpiHrmdOfficer(Ministry $ministry): User
{
    return User::factory()->create([
        'role_id' => kpiRole('HRM&D Officer', '3')->id,
        'ministry_id' => $ministry->id,
    ]);
}

it('lets a System Administrator create a KPI definition with the correct calculation_method (TC-FR-KPI-001)', function () {
    $admin = kpiSystemAdmin();
    $ministry = Ministry::factory()->create();

    $response = $this->actingAs($admin)->postJson('/api/v1/kpi-definitions', [
        'ministry_id' => $ministry->id,
        'name' => 'Number of quarterly reports submitted',
        'calculation_method' => 'auto',
        'data_source' => 'periodic_reports',
        'reporting_frequency' => 'quarterly',
    ]);

    $response->assertCreated();
    expect($response->json('data.calculation_method'))->toBe('auto')
        ->and($response->json('data.data_source'))->toBe('periodic_reports');

    $this->assertDatabaseHas('kpi_definitions', [
        'ministry_id' => $ministry->id,
        'name' => 'Number of quarterly reports submitted',
        'calculation_method' => 'auto',
    ]);

    $definition = KpiDefinition::query()->where('name', 'Number of quarterly reports submitted')->firstOrFail();
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'kpi_definition.created',
        'affected_entity_id' => $definition->id,
        'user_id' => $admin->id,
    ]);
});

it('rejects a non-System-Administrator from creating a KPI definition', function () {
    $ministry = Ministry::factory()->create();
    $director = kpiMinistryHqDirector($ministry);

    $this->actingAs($director)->postJson('/api/v1/kpi-definitions', [
        'ministry_id' => $ministry->id,
        'name' => 'Number of agreements signed',
        'calculation_method' => 'manual',
        'reporting_frequency' => 'half_yearly',
    ])->assertForbidden();
});

it('rejects creating an auto KPI definition without a data_source', function () {
    $admin = kpiSystemAdmin();
    $ministry = Ministry::factory()->create();

    $this->actingAs($admin)->postJson('/api/v1/kpi-definitions', [
        'ministry_id' => $ministry->id,
        'name' => 'Number of inquiries resolved',
        'calculation_method' => 'auto',
        'reporting_frequency' => 'quarterly',
    ])->assertStatus(422);
});

it('assigns a KPI profile to missions, linking correctly (TC-FR-KPI-002)', function () {
    $admin = kpiSystemAdmin();
    $ministry = Ministry::factory()->create();
    $kpi = KpiDefinition::factory()->create(['ministry_id' => $ministry->id]);
    $missionA = Mission::factory()->create();
    $missionB = Mission::factory()->create();
    MissionMinistryLink::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $missionA->id]);
    MissionMinistryLink::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $missionB->id]);

    $profileResponse = $this->actingAs($admin)->postJson('/api/v1/kpi-profiles', [
        'ministry_id' => $ministry->id,
        'name' => 'High-Volume Mission Profile',
        'kpi_definition_ids' => [$kpi->id],
    ]);
    $profileResponse->assertCreated();
    $profileId = $profileResponse->json('data.id');

    expect($profileResponse->json('data.kpi_definitions.0.id'))->toBe($kpi->id);

    $assignResponse = $this->actingAs($admin)->patchJson("/api/v1/kpi-profiles/{$profileId}/assign", [
        'mission_ids' => [$missionA->id, $missionB->id],
    ]);

    $assignResponse->assertOk();
    $assignedMissionIds = collect($assignResponse->json('data.assigned_missions'))->pluck('id')->sort()->values()->all();
    expect($assignedMissionIds)->toBe(collect([$missionA->id, $missionB->id])->sort()->values()->all());

    $this->assertDatabaseHas('kpi_profile_missions', [
        'kpi_profile_id' => $profileId,
        'mission_id' => $missionA->id,
    ]);
    $this->assertDatabaseHas('kpi_profile_missions', [
        'kpi_profile_id' => $profileId,
        'mission_id' => $missionB->id,
    ]);
});

it('keeps a KPI profile to its own department\'s KPIs and postings (NFR-SEC-006)', function () {
    $admin = kpiSystemAdmin();
    $ministry = Ministry::factory()->create();
    $other = Ministry::factory()->create();
    $foreignKpi = KpiDefinition::factory()->create(['ministry_id' => $other->id]);
    $foreignPosting = Mission::factory()->create();
    MissionMinistryLink::factory()->create(['ministry_id' => $other->id, 'mission_id' => $foreignPosting->id]);

    $this->actingAs($admin)->postJson('/api/v1/kpi-profiles', [
        'ministry_id' => $ministry->id,
        'name' => 'Borrowed KPIs',
        'kpi_definition_ids' => [$foreignKpi->id],
    ])->assertStatus(422);

    $profileId = $this->actingAs($admin)->postJson('/api/v1/kpi-profiles', [
        'ministry_id' => $ministry->id,
        'name' => 'Standard Mission Profile',
    ])->assertCreated()->json('data.id');

    $this->actingAs($admin)->patchJson("/api/v1/kpi-profiles/{$profileId}/assign", [
        'mission_ids' => [$foreignPosting->id],
    ])->assertStatus(422);

    $this->assertDatabaseMissing('kpi_profile_missions', ['kpi_profile_id' => $profileId]);
});

it('replaces a KPI profile\'s mission assignment rather than appending to it', function () {
    $admin = kpiSystemAdmin();
    $ministry = Ministry::factory()->create();
    $missionA = Mission::factory()->create();
    $missionB = Mission::factory()->create();
    MissionMinistryLink::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $missionA->id]);
    MissionMinistryLink::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $missionB->id]);

    $profileResponse = $this->actingAs($admin)->postJson('/api/v1/kpi-profiles', [
        'ministry_id' => $ministry->id,
        'name' => 'Standard Mission Profile',
    ]);
    $profileId = $profileResponse->json('data.id');

    $this->actingAs($admin)->patchJson("/api/v1/kpi-profiles/{$profileId}/assign", [
        'mission_ids' => [$missionA->id],
    ])->assertOk();

    $this->actingAs($admin)->patchJson("/api/v1/kpi-profiles/{$profileId}/assign", [
        'mission_ids' => [$missionB->id],
    ])->assertOk();

    $this->assertDatabaseMissing('kpi_profile_missions', [
        'kpi_profile_id' => $profileId,
        'mission_id' => $missionA->id,
    ]);
    $this->assertDatabaseHas('kpi_profile_missions', [
        'kpi_profile_id' => $profileId,
        'mission_id' => $missionB->id,
    ]);
});

it('inserts a new target row for an existing cycle rather than overwriting the prior one (TC-FR-KPI-005, BR-019)', function () {
    $ministry = Ministry::factory()->create();
    $ps = kpiMinistryPs($ministry);
    $kpi = KpiDefinition::factory()->create(['ministry_id' => $ministry->id]);
    $mission = Mission::factory()->create();
    MissionMinistryLink::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id]);

    $firstResponse = $this->actingAs($ps)->postJson('/api/v1/kpi-targets', [
        'kpi_definition_id' => $kpi->id,
        'mission_id' => $mission->id,
        'performance_cycle_label' => 'H1 2027',
        'cycle_start_date' => '2027-07-01',
        'target_value' => 10,
    ]);
    $firstResponse->assertCreated();

    $secondResponse = $this->actingAs($ps)->postJson('/api/v1/kpi-targets', [
        'kpi_definition_id' => $kpi->id,
        'mission_id' => $mission->id,
        'performance_cycle_label' => 'H1 2027',
        'cycle_start_date' => '2027-07-01',
        'target_value' => 25,
    ]);
    $secondResponse->assertCreated();

    expect($firstResponse->json('data.id'))->not->toBe($secondResponse->json('data.id'));

    expect(KpiTarget::query()->where('kpi_definition_id', $kpi->id)->where('mission_id', $mission->id)->count())->toBe(2);

    $this->assertDatabaseHas('kpi_targets', [
        'id' => $firstResponse->json('data.id'),
        'target_value' => 10,
    ]);
    $this->assertDatabaseHas('kpi_targets', [
        'id' => $secondResponse->json('data.id'),
        'target_value' => 25,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'kpi_target.created',
        'affected_entity_id' => $secondResponse->json('data.id'),
        'user_id' => $ps->id,
    ]);
});

it('leaves a prior cycle\'s target unchanged once a new cycle begins (TC-FR-KPI-004, BR-019)', function () {
    $ministry = Ministry::factory()->create();
    $ps = kpiMinistryPs($ministry);
    $kpi = KpiDefinition::factory()->create(['ministry_id' => $ministry->id]);
    $mission = Mission::factory()->create();
    MissionMinistryLink::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id]);

    $service = app(KpiService::class);
    $priorTarget = $service->setTarget($kpi, $mission, 'H1 2027', Carbon::create(2027, 1, 1), 10, $ps);

    // A new cycle (H2 2027) begins for the same mission/KPI.
    $service->setTarget($kpi, $mission, 'H2 2027', Carbon::create(2027, 7, 1), 20, $ps);

    $priorTarget->refresh();
    expect((float) $priorTarget->target_value)->toBe(10.0);

    // The prior cycle's target must still be the one the comparison
    // matrix reports for that cycle label, unaffected by the new write.
    $response = $this->actingAs($ps)->getJson('/api/v1/kpi-comparison?cycle_label=H1+2027');
    $response->assertOk();
    $row = collect($response->json('data.missions'))->firstWhere('mission_id', $mission->id);
    $kpiRow = collect($row['kpis'])->firstWhere('kpi_definition_id', $kpi->id);
    expect($kpiRow['target'])->toEqual(10.0);
});

it('lets a Ministry HQ Director set a KPI target (FR-KPI-005)', function () {
    $ministry = Ministry::factory()->create();
    $director = kpiMinistryHqDirector($ministry);
    $kpi = KpiDefinition::factory()->create(['ministry_id' => $ministry->id]);
    $mission = Mission::factory()->create();
    MissionMinistryLink::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id]);

    $this->actingAs($director)->postJson('/api/v1/kpi-targets', [
        'kpi_definition_id' => $kpi->id,
        'mission_id' => $mission->id,
        'performance_cycle_label' => 'H2 2027',
        'cycle_start_date' => '2027-01-01',
        'target_value' => 5,
    ])->assertCreated();
});

it('rejects a Ministry Attache from setting a KPI target', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = kpiMinistryAttache($ministry, $mission);
    $kpi = KpiDefinition::factory()->create(['ministry_id' => $ministry->id]);

    $this->actingAs($attache)->postJson('/api/v1/kpi-targets', [
        'kpi_definition_id' => $kpi->id,
        'mission_id' => $mission->id,
        'performance_cycle_label' => 'H1 2027',
        'cycle_start_date' => '2027-07-01',
        'target_value' => 5,
    ])->assertForbidden();
});

it('404s when a Ministry PS tries to set a target for a KPI outside their own ministry (NFR-SEC-006)', function () {
    $ownMinistry = Ministry::factory()->create();
    $otherMinistry = Ministry::factory()->create();
    $ps = kpiMinistryPs($ownMinistry);
    $foreignKpi = KpiDefinition::factory()->create(['ministry_id' => $otherMinistry->id]);
    $mission = Mission::factory()->create();

    $this->actingAs($ps)->postJson('/api/v1/kpi-targets', [
        'kpi_definition_id' => $foreignKpi->id,
        'mission_id' => $mission->id,
        'performance_cycle_label' => 'H1 2027',
        'cycle_start_date' => '2027-07-01',
        'target_value' => 5,
    ])->assertNotFound();
});

// --- Session 33: FR-KPI-006 to 016, FR-SDT-016 to 018 -----------------

it('lets a Ministry Attache record a manual KPI actual (TC-FR-KPI-007)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    MissionMinistryLink::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id]);
    $attache = kpiMinistryAttache($ministry, $mission);
    $kpi = KpiDefinition::factory()->create(['ministry_id' => $ministry->id, 'calculation_method' => 'manual']);

    $response = $this->actingAs($attache)->postJson('/api/v1/kpi-actuals', [
        'kpi_definition_id' => $kpi->id,
        'mission_id' => $mission->id,
        'period_label' => 'Q1 2026',
        'period_start_date' => '2026-07-01',
        'actual_value' => 4,
    ]);

    $response->assertCreated();
    expect($response->json('data.calculation_type'))->toBe('manual');

    $this->assertDatabaseHas('kpi_actuals', [
        'kpi_definition_id' => $kpi->id,
        'mission_id' => $mission->id,
        'period_label' => 'Q1 2026',
        'actual_value' => 4.00,
        'entered_by_user_id' => $attache->id,
        'calculation_type' => 'manual',
    ]);
});

it('lets a Ministry HQ Officer record a manual KPI actual (FR-KPI-007)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    MissionMinistryLink::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id]);
    $officer = kpiMinistryHqOfficer($ministry);
    $kpi = KpiDefinition::factory()->create(['ministry_id' => $ministry->id, 'calculation_method' => 'manual']);

    $this->actingAs($officer)->postJson('/api/v1/kpi-actuals', [
        'kpi_definition_id' => $kpi->id,
        'mission_id' => $mission->id,
        'period_label' => 'Q1 2026',
        'period_start_date' => '2026-07-01',
        'actual_value' => 9,
    ])->assertCreated();
});

it('rejects a Ministry PS from recording a manual KPI actual', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    MissionMinistryLink::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id]);
    $ps = kpiMinistryPs($ministry);
    $kpi = KpiDefinition::factory()->create(['ministry_id' => $ministry->id, 'calculation_method' => 'manual']);

    $this->actingAs($ps)->postJson('/api/v1/kpi-actuals', [
        'kpi_definition_id' => $kpi->id,
        'mission_id' => $mission->id,
        'period_label' => 'Q1 2026',
        'period_start_date' => '2026-07-01',
        'actual_value' => 4,
    ])->assertForbidden();
});

it('re-recording the same mission/kpi/quarter upserts rather than duplicating', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = kpiMinistryAttache($ministry, $mission);
    $kpi = KpiDefinition::factory()->create(['ministry_id' => $ministry->id, 'calculation_method' => 'manual']);

    $service = app(KpiService::class);
    $service->recordActual($kpi, $mission, 'Q1 2027', Carbon::create(2027, 7, 1), 10.0, $attache, 'manual');
    $service->recordActual($kpi, $mission, 'Q1 2027', Carbon::create(2027, 7, 1), 15.0, $attache, 'manual');

    expect(KpiActual::query()->where('kpi_definition_id', $kpi->id)->where('mission_id', $mission->id)->count())->toBe(1);

    $this->assertDatabaseHas('kpi_actuals', [
        'kpi_definition_id' => $kpi->id,
        'mission_id' => $mission->id,
        'actual_value' => 15.00,
    ]);
});

it('aggregates two on-time quarters into H1 correctly (TC-FR-KPI-016-A)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $kpi = KpiDefinition::factory()->create(['ministry_id' => $ministry->id, 'calculation_method' => 'manual']);
    $attache = kpiMinistryAttache($ministry, $mission);
    $service = app(KpiService::class);

    $service->recordActual($kpi, $mission, 'Q1 2027', Carbon::create(2027, 7, 1), 10.0, $attache, 'manual');
    $service->recordActual($kpi, $mission, 'Q2 2027', Carbon::create(2027, 10, 1), 15.0, $attache, 'manual');

    $aggregation = $service->computeAggregation($kpi, $mission, 2027);

    expect($aggregation['H1']['total'])->toBe(25.0)
        ->and($aggregation['H1']['quarters_reported'])->toBe(2)
        ->and($aggregation['H1']['quarters']['Q1 2027'])->toBe(10.0)
        ->and($aggregation['H1']['quarters']['Q2 2027'])->toBe(15.0)
        ->and($aggregation['H2']['total'])->toBeNull()
        ->and($aggregation['H2']['quarters_reported'])->toBe(0);
});

it('still includes a late Q2 submission in H1 aggregation (TC-FR-KPI-016-B)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $kpi = KpiDefinition::factory()->create(['ministry_id' => $ministry->id, 'calculation_method' => 'manual']);
    $attache = kpiMinistryAttache($ministry, $mission);
    $service = app(KpiService::class);

    $service->recordActual($kpi, $mission, 'Q1 2027', Carbon::create(2027, 7, 1), 10.0, $attache, 'manual');

    // Q2 2027 (Oct-Dec 2027) is recorded here as though it were entered
    // long after its own quarter end (its created_at falls in 2028) — a
    // late submission. Inclusion in the aggregation is keyed on
    // period_start_date, never on created_at/updated_at, so it must
    // still be counted exactly like an on-time actual.
    Carbon::setTestNow('2028-02-01');
    $service->recordActual($kpi, $mission, 'Q2 2027', Carbon::create(2027, 10, 1), 20.0, $attache, 'manual');
    Carbon::setTestNow();

    $lateActual = KpiActual::query()->where('kpi_definition_id', $kpi->id)->where('period_label', 'Q2 2027')->firstOrFail();
    expect($lateActual->created_at->year)->toBe(2028);

    $aggregation = $service->computeAggregation($kpi, $mission, 2027);

    expect($aggregation['H1']['total'])->toBe(30.0)
        ->and($aggregation['H1']['quarters_reported'])->toBe(2);
});

it('reflects an edited Q1 actual when H1 aggregation is re-run (TC-FR-KPI-016-C)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $kpi = KpiDefinition::factory()->create(['ministry_id' => $ministry->id, 'calculation_method' => 'manual']);
    $attache = kpiMinistryAttache($ministry, $mission);
    $service = app(KpiService::class);

    $service->recordActual($kpi, $mission, 'Q1 2027', Carbon::create(2027, 7, 1), 10.0, $attache, 'manual');
    $firstAggregation = $service->computeAggregation($kpi, $mission, 2027);
    expect($firstAggregation['H1']['total'])->toBe(10.0);

    // Editing the Q1 actual after the H1 aggregation has already run once
    // must upsert in place (asserted separately above) and be reflected
    // the next time the aggregation is computed, not cached/stale.
    $service->recordActual($kpi, $mission, 'Q1 2027', Carbon::create(2027, 7, 1), 18.0, $attache, 'manual');

    $secondAggregation = $service->computeAggregation($kpi, $mission, 2027);
    expect($secondAggregation['H1']['total'])->toBe(18.0);
});

it('derives an auto-calculated KPI actual correctly from its data_source (TC-FR-KPI-009)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    MissionMinistryLink::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id]);

    $kpi = KpiDefinition::factory()->create([
        'ministry_id' => $ministry->id,
        'calculation_method' => 'auto',
        'data_source' => 'alerts.count_submitted',
        'active' => true,
    ]);

    $period = app(ReportService::class)->currentSubmissionPeriod();

    // Three alerts inside the current submission quarter must be counted...
    Alert::factory()->count(3)->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'created_at' => $period['start']->copy()->addDays(2),
    ]);

    // ...one alert outside the quarter, and one for a different mission,
    // must not be.
    Alert::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'created_at' => $period['start']->copy()->subMonths(4),
    ]);
    Alert::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => Mission::factory()->create()->id,
        'created_at' => $period['start']->copy()->addDays(2),
    ]);

    Artisan::call('foams:compute-kpi-actuals');

    $this->assertDatabaseHas('kpi_actuals', [
        'kpi_definition_id' => $kpi->id,
        'mission_id' => $mission->id,
        'period_label' => $period['label'],
        'actual_value' => 3.00,
        'calculation_type' => 'auto',
    ]);

    $actual = KpiActual::query()
        ->where('kpi_definition_id', $kpi->id)
        ->where('mission_id', $mission->id)
        ->firstOrFail();
    expect($actual->entered_by_user_id)->toBeNull();
});

it('builds the national comparison matrix across missions and KPIs (TC-FR-KPI-013)', function () {
    $ministry = Ministry::factory()->create();
    $missionA = Mission::factory()->create(['name' => 'Alpha Mission']);
    $missionB = Mission::factory()->create(['name' => 'Beta Mission']);
    MissionMinistryLink::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $missionA->id]);
    MissionMinistryLink::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $missionB->id]);

    $kpi = KpiDefinition::factory()->create(['ministry_id' => $ministry->id, 'calculation_method' => 'manual']);
    $director = kpiMinistryHqDirector($ministry);
    $service = app(KpiService::class);

    $service->setTarget($kpi, $missionA, 'H1 2027', Carbon::create(2027, 7, 1), 20, $director);
    $service->recordActual($kpi, $missionA, 'Q1 2027', Carbon::create(2027, 7, 1), 16.0, $director, 'manual');

    // H1 2027 (Jul–Dec 2027) judged once final, after Q2's 15 January
    // reporting deadline: 16 of 20 is at risk.
    Carbon::setTestNow('2028-02-01');
    $response = $this->actingAs($director)->getJson('/api/v1/kpi-comparison?cycle_label=H1+2027');

    $response->assertOk();
    $missions = collect($response->json('data.missions'))->keyBy('mission_id');
    expect($missions->has($missionA->id))->toBeTrue()
        ->and($missions->has($missionB->id))->toBeTrue();

    // PHP floats that lose their fractional part encode to JSON without a
    // decimal point (20.0 -> "20"), so json_decode hands them back as int
    // — toEqual (loose) rather than toBe (strict) avoids a false failure
    // on that round-trip, not a defect in the response itself.
    $missionARow = collect($missions[$missionA->id]['kpis'])->firstWhere('kpi_definition_id', $kpi->id);
    expect($missionARow['target'])->toEqual(20.0)
        ->and($missionARow['actual'])->toEqual(16.0)
        ->and($missionARow['status'])->toBe('at_risk');
});

it('rejects a Ministry Attache from viewing the comparison matrix', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = kpiMinistryAttache($ministry, $mission);

    $this->actingAs($attache)->getJson('/api/v1/kpi-comparison?cycle_label=H1+2027')->assertForbidden();
});

it('downloads a CSV performance report for a mission (TC-FR-KPI-015)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create(['name' => 'Gamma Mission']);
    MissionMinistryLink::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id]);
    $kpi = KpiDefinition::factory()->create([
        'ministry_id' => $ministry->id,
        'name' => 'Number of agreements signed',
        'calculation_method' => 'manual',
    ]);
    $ps = kpiMinistryPs($ministry);

    app(KpiService::class)->recordActual($kpi, $mission, 'Q1 2027', Carbon::create(2027, 7, 1), 5.0, $ps, 'manual');

    $response = $this->actingAs($ps)->get('/api/v1/kpi-reports/'.$mission->id.'?cycle_label=Q1+2027');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $content = $response->streamedContent();
    expect($content)->toContain('Gamma Mission')
        ->and($content)->toContain('Number of agreements signed');
});

it('lets an HRM&D Officer access the hrmd-dashboard but not /api/v1/alerts (TC-FR-SDT-016)', function () {
    $ministry = Ministry::factory()->create();
    $officer = kpiHrmdOfficer($ministry);

    $this->actingAs($officer)->getJson('/api/v1/sdt/hrmd-dashboard?cycle_label=H1+2027')->assertOk();

    $this->actingAs($officer)->getJson('/api/v1/alerts')->assertForbidden();
});

it('rejects a Ministry HQ Director from accessing the HRM&D dashboard', function () {
    $ministry = Ministry::factory()->create();
    $director = kpiMinistryHqDirector($ministry);

    $this->actingAs($director)->getJson('/api/v1/sdt/hrmd-dashboard?cycle_label=H1+2027')->assertForbidden();
});

it('lets an HRM&D Officer generate an individual attache performance summary and logs the access (TC-FR-SDT-017)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = kpiMinistryAttache($ministry, $mission);
    $officer = kpiHrmdOfficer($ministry);
    $kpi = KpiDefinition::factory()->create(['ministry_id' => $ministry->id, 'calculation_method' => 'manual']);

    app(KpiService::class)->recordActual($kpi, $mission, 'Q1 2027', Carbon::create(2027, 7, 1), 7.0, $attache, 'manual');

    $response = $this->actingAs($officer)->getJson("/api/v1/sdt/hrmd-dashboard/{$attache->id}/summary?period_label=Q1+2027");

    $response->assertOk();
    expect($response->json('data.attache.id'))->toBe($attache->id);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'kpi.hrmd_attache_summary.generated',
        'affected_entity_id' => $attache->id,
        'user_id' => $officer->id,
    ]);
});

it('logs every HRM&D dashboard access to the audit trail (FR-KPI-011 AC1)', function () {
    $ministry = Ministry::factory()->create();
    $officer = kpiHrmdOfficer($ministry);

    $this->actingAs($officer)->getJson('/api/v1/sdt/hrmd-dashboard?cycle_label=H1+2027')->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'kpi.hrmd_dashboard.accessed',
        'user_id' => $officer->id,
    ]);
});

it('rejects an HRM&D Officer from every non-KPI Layer 2 endpoint (TC-FR-SDT-018)', function () {
    $ministry = Ministry::factory()->create();
    $officer = kpiHrmdOfficer($ministry);

    $this->actingAs($officer)->getJson('/api/v1/alerts')->assertForbidden();
    $this->actingAs($officer)->getJson('/api/v1/inquiries')->assertForbidden();
    $this->actingAs($officer)->getJson('/api/v1/directives')->assertForbidden();
});

it('404s when an HRM&D Officer requests a summary for an attache outside their own ministry (NFR-SEC-006)', function () {
    $ownMinistry = Ministry::factory()->create();
    $otherMinistry = Ministry::factory()->create();
    $otherMission = Mission::factory()->create();
    $officer = kpiHrmdOfficer($ownMinistry);
    $foreignAttache = kpiMinistryAttache($otherMinistry, $otherMission);

    $this->actingAs($officer)
        ->getJson("/api/v1/sdt/hrmd-dashboard/{$foreignAttache->id}/summary?period_label=Q1+2027")
        ->assertNotFound();
});
