<?php

use App\Models\KpiDefinition;
use App\Models\KpiProfile;
use App\Models\KpiProfileTarget;
use App\Models\KpiTarget;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\Role;
use App\Models\User;
use App\Services\KpiService;
use Illuminate\Support\Carbon;

/**
 * FR-KPI-002 to 005, BR-019 — setting targets through the planner, and the
 * targets flowing through to the dashboard, comparison and report. The
 * clock is 2 November 2026: H1 2026 is the current cycle; H2 2026 has ended.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-11-02');
});

afterEach(function () {
    Carbon::setTestNow();
});

function planRole(string $name): Role
{
    return Role::query()->firstOrCreate(['name' => $name], ['layer' => '2', 'scope' => 'ministry']);
}

function planUser(string $role, Ministry $ministry, ?Mission $mission = null): User
{
    return User::factory()->create(['role_id' => planRole($role)->id, 'ministry_id' => $ministry->id, 'mission_id' => $mission?->id]);
}

function planPosting(Ministry $ministry, string $name): Mission
{
    $mission = Mission::factory()->create(['name' => $name]);
    MissionMinistryLink::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id]);

    return $mission;
}

function planKpi(Ministry $ministry, string $name): KpiDefinition
{
    return KpiDefinition::factory()->create(['ministry_id' => $ministry->id, 'name' => $name, 'calculation_method' => 'manual', 'data_source' => null, 'active' => true]);
}

/**
 * @param  array<int, KpiDefinition>  $kpis
 * @param  array<int, Mission>  $missions
 */
function planProfile(Ministry $ministry, string $name, array $kpis, array $missions): KpiProfile
{
    $profile = KpiProfile::factory()->create(['ministry_id' => $ministry->id, 'name' => $name]);
    foreach ($kpis as $kpi) {
        $profile->kpiProfileDefinitions()->create(['kpi_definition_id' => $kpi->id]);
    }
    app(KpiService::class)->assignProfileToMissions($profile, collect($missions)->pluck('id')->all(), User::factory()->create());

    return $profile;
}

it('lays out every mission x KPI for the cycle with its target source and the previous cycle (TC-FR-KPI-005-B)', function () {
    $ministry = Ministry::factory()->create();
    $london = planPosting($ministry, 'London');
    $dubai = planPosting($ministry, 'Dubai');
    $director = planUser('Ministry HQ Director', $ministry);
    $kpi = planKpi($ministry, 'Agreements');
    $service = app(KpiService::class);
    $service->setTarget($kpi, $london, 'H1 2026', Carbon::parse('2026-07-01'), 6, $director);
    $service->setTarget($kpi, $london, 'H2 2026', Carbon::parse('2026-01-01'), 4, $director);
    $service->recordActual($kpi, $london, 'Q3 2026', Carbon::parse('2026-01-01'), 5, null, 'manual');

    $response = $this->actingAs($director)->getJson('/api/v1/kpi-targets/plan?cycle_label=H1+2026')->assertOk();
    $missions = collect($response->json('data.missions'))->keyBy('name');

    expect($response->json('data.cycle.editable'))->toBeTrue()
        ->and($response->json('data.previous_cycle.label'))->toBe('H2 2026')
        ->and($missions['London']['targets'][$kpi->id]['value'])->toEqual(6.0)
        ->and($missions['London']['targets'][$kpi->id]['source'])->toBe('mission')
        ->and($missions['London']['targets'][$kpi->id]['override']['set_by']['id'])->toBe($director->id)
        ->and($missions['London']['targets'][$kpi->id]['previous'])->toEqual(['target' => 4.0, 'actual' => 5.0, 'status' => 'on_track'])
        ->and($missions['Dubai']['targets'][$kpi->id]['value'])->toBeNull()
        ->and($response->json('data.summary'))->toMatchArray(['required' => 2, 'set' => 1, 'missing' => 1, 'overrides' => 1, 'missions_complete' => 1])
        ->and(collect($response->json('data.cycles'))->firstWhere('label', 'H2 2026')['editable'])->toBeFalse();
});

it('defaults the planner to the current cycle', function () {
    $ministry = Ministry::factory()->create();
    $director = planUser('Ministry PS', $ministry);

    expect($this->actingAs($director)->getJson('/api/v1/kpi-targets/plan')->assertOk()->json('data.cycle.label'))->toBe('H1 2026');
});

it('saves a batch of targets as new versions, skipping unchanged ones (TC-FR-KPI-004-B, BR-019)', function () {
    $ministry = Ministry::factory()->create();
    $london = planPosting($ministry, 'London');
    $dubai = planPosting($ministry, 'Dubai');
    $ps = planUser('Ministry PS', $ministry);
    $kpi = planKpi($ministry, 'Agreements');

    $payload = ['performance_cycle_label' => 'H1 2026', 'targets' => [
        ['kpi_definition_id' => $kpi->id, 'mission_id' => $london->id, 'target_value' => 6],
        ['kpi_definition_id' => $kpi->id, 'mission_id' => $dubai->id, 'target_value' => 3],
    ]];

    $first = $this->actingAs($ps)->postJson('/api/v1/kpi-targets/batch', $payload)->assertOk();
    expect($first->json('data.saved'))->toBe(2)->and($first->json('data.unchanged'))->toBe(0)
        ->and($first->json('data.plan.summary.set'))->toBe(2);

    $again = $this->actingAs($ps)->postJson('/api/v1/kpi-targets/batch', $payload)->assertOk();
    expect($again->json('data.saved'))->toBe(0)->and($again->json('data.unchanged'))->toBe(2)
        ->and(KpiTarget::query()->count())->toBe(2);

    Carbon::setTestNow('2026-11-03');
    $payload['targets'][0]['target_value'] = 8;
    $payload['note'] = 'Raised after the trade fair schedule was confirmed.';
    $revised = $this->actingAs($ps)->postJson('/api/v1/kpi-targets/batch', $payload)->assertOk();

    expect($revised->json('data.saved'))->toBe(1)
        ->and(KpiTarget::query()->where('mission_id', $london->id)->count())->toBe(2)
        ->and(KpiTarget::query()->where('mission_id', $london->id)->where('target_value', 6)->exists())->toBeTrue();

    $london = collect($revised->json('data.plan.missions'))->firstWhere('name', 'London');
    expect($london['targets'][$kpi->id]['value'])->toEqual(8.0)
        ->and($london['targets'][$kpi->id]['override']['versions'])->toBe(2)
        ->and($london['targets'][$kpi->id]['override']['note'])->toBe('Raised after the trade fair schedule was confirmed.');

    $this->assertDatabaseHas('audit_logs', ['action' => 'kpi_target.created', 'user_id' => $ps->id]);
});

it('saves all or nothing when one entry is invalid', function () {
    $ministry = Ministry::factory()->create();
    $london = planPosting($ministry, 'London');
    $foreign = planPosting(Ministry::factory()->create(), 'Elsewhere');
    $director = planUser('Ministry HQ Director', $ministry);
    $kpi = planKpi($ministry, 'Agreements');

    $this->actingAs($director)->postJson('/api/v1/kpi-targets/batch', ['performance_cycle_label' => 'H1 2026', 'targets' => [
        ['kpi_definition_id' => $kpi->id, 'mission_id' => $london->id, 'target_value' => 6],
        ['kpi_definition_id' => $kpi->id, 'mission_id' => $foreign->id, 'target_value' => 3],
    ]])->assertStatus(422);

    expect(KpiTarget::query()->count())->toBe(0);
});

it('locks a cycle that has ended and one beyond the planning horizon (TC-FR-KPI-004-C)', function (string $cycle) {
    $ministry = Ministry::factory()->create();
    $london = planPosting($ministry, 'London');
    $director = planUser('Ministry HQ Director', $ministry);
    $kpi = planKpi($ministry, 'Agreements');

    $this->actingAs($director)->postJson('/api/v1/kpi-targets/batch', ['performance_cycle_label' => $cycle, 'targets' => [
        ['kpi_definition_id' => $kpi->id, 'mission_id' => $london->id, 'target_value' => 6],
    ]])->assertStatus(422);
    $this->actingAs($director)->postJson('/api/v1/kpi-targets', [
        'kpi_definition_id' => $kpi->id, 'mission_id' => $london->id, 'performance_cycle_label' => $cycle, 'target_value' => 6,
    ])->assertStatus(422);

    expect(KpiTarget::query()->count())->toBe(0);
})->with(['ended' => ['H2 2026'], 'beyond the horizon' => ['H1 2028']]);

it('rejects malformed targets', function (array $override) {
    $ministry = Ministry::factory()->create();
    $london = planPosting($ministry, 'London');
    $director = planUser('Ministry HQ Director', $ministry);
    $kpi = planKpi($ministry, 'Agreements');

    $this->actingAs($director)->postJson('/api/v1/kpi-targets', [
        'kpi_definition_id' => $kpi->id,
        'mission_id' => $london->id,
        'performance_cycle_label' => 'H1 2026',
        'target_value' => 6,
        ...$override,
    ])->assertStatus(422);
})->with([
    'zero' => [['target_value' => 0]],
    'negative' => [['target_value' => -2]],
    'not a number' => [['target_value' => 'lots']],
    'too large' => [['target_value' => 99999999999999]],
    'quarter label' => [['performance_cycle_label' => 'Q1 2026']],
    'free-text label' => [['performance_cycle_label' => 'first half']],
    'mismatched start date' => [['cycle_start_date' => '2026-01-01']],
    'both a mission and a profile' => [['kpi_profile_id' => '01a0f572-1168-714d-b3d9-e0538ce206e7']],
    'missing value' => [['target_value' => null]],
]);

it('applies a profile default to its missions, overridden per mission without changing the profile (TC-FR-KPI-003)', function () {
    $ministry = Ministry::factory()->create();
    $london = planPosting($ministry, 'London');
    $dubai = planPosting($ministry, 'Dubai');
    $director = planUser('Ministry HQ Director', $ministry);
    $kpi = planKpi($ministry, 'Agreements');
    $profile = planProfile($ministry, 'High-Volume Mission', [$kpi], [$london, $dubai]);

    $this->actingAs($director)->postJson('/api/v1/kpi-targets/batch', ['performance_cycle_label' => 'H1 2026', 'targets' => [
        ['kpi_definition_id' => $kpi->id, 'kpi_profile_id' => $profile->id, 'target_value' => 10],
        ['kpi_definition_id' => $kpi->id, 'mission_id' => $london->id, 'target_value' => 14],
    ]])->assertOk();

    $plan = collect($this->actingAs($director)->getJson('/api/v1/kpi-targets/plan?cycle_label=H1+2026')->json('data.missions'))->keyBy('name');
    expect($plan['London']['targets'][$kpi->id])->toMatchArray(['value' => 14.0, 'source' => 'mission'])
        ->and($plan['London']['targets'][$kpi->id]['profile_default']['value'])->toEqual(10.0)
        ->and($plan['Dubai']['targets'][$kpi->id])->toMatchArray(['value' => 10.0, 'source' => 'profile'])
        ->and(KpiProfileTarget::query()->sole()->target_value)->toEqual('10.00');

    $dashboard = collect($this->actingAs($director)->getJson("/api/v1/kpi-dashboard?mission_id={$dubai->id}&period=H1+2026")->json('data.kpis'))->keyBy('name');
    expect($dashboard['Agreements']['target'])->toEqual(10.0)
        ->and($dashboard['Agreements']['target_source'])->toBe('profile');
});

it('shows a mission on a profile only that profile\'s KPIs (TC-FR-KPI-002-B)', function () {
    $ministry = Ministry::factory()->create();
    $london = planPosting($ministry, 'London');
    $dubai = planPosting($ministry, 'Dubai');
    $director = planUser('Ministry HQ Director', $ministry);
    $agreements = planKpi($ministry, 'Agreements');
    $briefs = planKpi($ministry, 'Trade briefs');
    planProfile($ministry, 'Standard Mission', [$agreements], [$london]);

    $londonKpis = collect($this->actingAs($director)->getJson("/api/v1/kpi-dashboard?mission_id={$london->id}&period=H2+2026")->json('data.kpis'))->pluck('name')->all();
    $dubaiKpis = collect($this->actingAs($director)->getJson("/api/v1/kpi-dashboard?mission_id={$dubai->id}&period=H2+2026")->json('data.kpis'))->pluck('name')->all();

    expect($londonKpis)->toBe(['Agreements'])
        ->and($dubaiKpis)->toBe(['Agreements', 'Trade briefs']);

    $this->actingAs($director)->postJson('/api/v1/kpi-targets', [
        'kpi_definition_id' => $briefs->id, 'mission_id' => $london->id, 'performance_cycle_label' => 'H1 2026', 'target_value' => 3,
    ])->assertStatus(422);
});

it('moves a mission to the profile it is newly assigned to', function () {
    $ministry = Ministry::factory()->create();
    $london = planPosting($ministry, 'London');
    $kpi = planKpi($ministry, 'Agreements');
    $standard = planProfile($ministry, 'Standard', [$kpi], [$london]);
    $highVolume = planProfile($ministry, 'High-Volume', [$kpi], [$london]);

    expect($standard->kpiProfileMissions()->count())->toBe(0)
        ->and($highVolume->kpiProfileMissions()->pluck('mission_id')->all())->toBe([$london->id]);
});

it('keeps every version of a target, marking the one in force per cycle (TC-FR-KPI-004-D)', function () {
    $ministry = Ministry::factory()->create();
    $london = planPosting($ministry, 'London');
    $director = planUser('Ministry HQ Director', $ministry);
    $kpi = planKpi($ministry, 'Agreements');
    $service = app(KpiService::class);

    Carbon::setTestNow('2026-01-10');
    $service->setTarget($kpi, $london, 'H2 2026', Carbon::parse('2026-01-01'), 4, $director);
    Carbon::setTestNow('2026-07-02');
    $service->setTarget($kpi, $london, 'H1 2026', Carbon::parse('2026-07-01'), 5, $director);
    Carbon::setTestNow('2026-08-01');
    $service->setTarget($kpi, $london, 'H1 2026', Carbon::parse('2026-07-01'), 7, $director, 'Mid-cycle revision');
    Carbon::setTestNow('2026-11-02');

    $history = $this->actingAs($director)->getJson("/api/v1/kpi-targets/history?kpi_definition_id={$kpi->id}&mission_id={$london->id}")->assertOk()->json('data');

    expect(collect($history)->map(fn (array $row): array => [$row['cycle_label'], $row['value'], $row['in_force']])->all())->toEqual([
        ['H1 2026', 7.0, true],
        ['H1 2026', 5.0, false],
        ['H2 2026', 4.0, true],
    ])->and($history[0]['note'])->toBe('Mid-cycle revision');
});

it('carries a target from the planner through the dashboard, comparison and report (TC-KPI-FLOW-A)', function () {
    $ministry = Ministry::factory()->create();
    $london = planPosting($ministry, 'London');
    $ps = planUser('Ministry PS', $ministry);
    $kpi = planKpi($ministry, 'Agreements');
    app(KpiService::class)->recordActual($kpi, $london, 'Q1 2026', Carbon::parse('2026-07-01'), 4, $ps, 'manual');

    $this->actingAs($ps)->postJson('/api/v1/kpi-targets/batch', ['performance_cycle_label' => 'H1 2026', 'targets' => [
        ['kpi_definition_id' => $kpi->id, 'mission_id' => $london->id, 'target_value' => 8],
    ]])->assertOk();

    $dashboardRow = fn () => collect($this->actingAs($ps)->getJson("/api/v1/kpi-dashboard?mission_id={$london->id}&period=H1+2026")->json('data.kpis'))->firstWhere('name', 'Agreements');
    $comparisonCell = fn () => collect(collect($this->actingAs($ps)->getJson('/api/v1/kpi-comparison?period=H1+2026')->json('data.missions'))->firstWhere('mission_id', $london->id)['kpis'])->firstWhere('kpi_definition_id', $kpi->id);

    // 4 against 8 expected 4 by now (Q1 settled): on track, in both views.
    expect($dashboardRow())->toMatchArray(['target' => 8.0, 'actual' => 4.0, 'expected' => 4.0, 'status' => 'on_track'])
        ->and($comparisonCell())->toMatchArray(['target' => 8.0, 'actual' => 4.0, 'status' => 'on_track']);

    Carbon::setTestNow('2026-11-03');
    $this->actingAs($ps)->postJson('/api/v1/kpi-targets/batch', ['performance_cycle_label' => 'H1 2026', 'targets' => [
        ['kpi_definition_id' => $kpi->id, 'mission_id' => $london->id, 'target_value' => 12],
    ]])->assertOk();

    // Raised to 12: 6 expected by now, 4 is 67% of that — below target.
    expect($dashboardRow())->toMatchArray(['target' => 12.0, 'expected' => 6.0, 'status' => 'below_target'])
        ->and($comparisonCell())->toMatchArray(['target' => 12.0, 'status' => 'below_target'])
        ->and(KpiTarget::query()->where('mission_id', $london->id)->count())->toBe(2);

    $csv = $this->actingAs($ps)->get("/api/v1/kpi-reports/{$london->id}?period=H1+2026")->assertOk()->streamedContent();
    expect($csv)->toContain('London')->toContain('Agreements')->toContain(',12,4,6,33.3,-8,"below target"');
});
