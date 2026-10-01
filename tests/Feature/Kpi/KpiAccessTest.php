<?php

use App\Models\KpiActual;
use App\Models\KpiDefinition;
use App\Models\KpiTarget;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\PeriodicReport;
use App\Models\Role;
use App\Models\User;
use App\Services\KpiService;
use Illuminate\Support\Carbon;

/**
 * Who may see and change KPI data (FR-KPI-005, 007, 010, 011, 013; BR-020;
 * NFR-SEC-006), enforced by the API itself, and the rules for manually
 * entered actuals. The clock is 2 November 2026.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-11-02');
});

afterEach(function () {
    Carbon::setTestNow();
});

function accessRole(string $name): Role
{
    return Role::query()->firstOrCreate(['name' => $name], ['layer' => '2', 'scope' => 'ministry']);
}

function accessUser(string $role, ?Ministry $ministry, ?Mission $mission = null): User
{
    return User::factory()->create(['role_id' => accessRole($role)->id, 'ministry_id' => $ministry?->id, 'mission_id' => $mission?->id]);
}

function accessPosting(Ministry $ministry, string $name): Mission
{
    $mission = Mission::factory()->create(['name' => $name]);
    MissionMinistryLink::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id]);

    return $mission;
}

function accessKpi(Ministry $ministry, string $name, string $method = 'manual', ?string $source = null): KpiDefinition
{
    return KpiDefinition::factory()->create(['ministry_id' => $ministry->id, 'name' => $name, 'calculation_method' => $method, 'data_source' => $source, 'active' => true]);
}

it('gives an attache a read-only view of their own mission only (TC-FR-KPI-010-A)', function () {
    $ministry = Ministry::factory()->create();
    $london = accessPosting($ministry, 'London');
    $dubai = accessPosting($ministry, 'Dubai');
    $attache = accessUser('Ministry Attache', $ministry, $london);
    $kpi = accessKpi($ministry, 'Agreements');

    $response = $this->actingAs($attache)->getJson('/api/v1/kpi-dashboard?period=H2+2026')->assertOk();

    expect($response->json('data.mission.id'))->toBe($london->id)
        ->and(collect($response->json('data.missions_available'))->pluck('id')->all())->toBe([$london->id])
        ->and($response->json('data.can'))->toMatchArray(['choose_mission' => false, 'set_targets' => false, 'compare' => false, 'download_report' => false]);

    $this->actingAs($attache)->getJson("/api/v1/kpi-dashboard?mission_id={$dubai->id}")->assertForbidden();
    $this->actingAs($attache)->getJson('/api/v1/kpi-comparison?period=H2+2026')->assertForbidden();
    $this->actingAs($attache)->getJson('/api/v1/kpi-targets/plan')->assertForbidden();
    $this->actingAs($attache)->getJson("/api/v1/kpi-targets/history?kpi_definition_id={$kpi->id}&mission_id={$london->id}")->assertForbidden();
    $this->actingAs($attache)->postJson('/api/v1/kpi-targets/batch', ['performance_cycle_label' => 'H1 2026', 'targets' => [
        ['kpi_definition_id' => $kpi->id, 'mission_id' => $london->id, 'target_value' => 5],
    ]])->assertForbidden();
    $this->actingAs($attache)->get("/api/v1/kpi-reports/{$london->id}?period=H2+2026")->assertForbidden();

    expect(KpiTarget::query()->count())->toBe(0);
});

it('gives the HRM&D Officer a read-only, audited dashboard across missions (TC-FR-KPI-011-B)', function () {
    $ministry = Ministry::factory()->create();
    $london = accessPosting($ministry, 'London');
    $officer = accessUser('HRM&D Officer', $ministry);
    $kpi = accessKpi($ministry, 'Agreements');
    PeriodicReport::factory()->create([
        'ministry_id' => $ministry->id, 'mission_id' => $london->id, 'reporting_period_label' => 'Q3 2026',
        'period_start_date' => '2026-01-01', 'period_end_date' => '2026-03-31', 'status' => 'submitted', 'submitted_at' => '2026-04-02',
    ]);

    $response = $this->actingAs($officer)->getJson("/api/v1/kpi-dashboard?mission_id={$london->id}&period=H2+2026")->assertOk();

    expect($response->json('data.can.set_targets'))->toBeFalse()
        ->and(collect($response->json('data.compliance'))->firstWhere('label', 'Q3 2026')['report_id'])->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'kpi.dashboard.accessed', 'user_id' => $officer->id]);

    $this->actingAs($officer)->get("/api/v1/kpi-reports/{$london->id}?period=H2+2026")->assertOk();
    $this->assertDatabaseHas('audit_logs', ['action' => 'kpi.report.generated', 'user_id' => $officer->id]);

    $this->actingAs($officer)->getJson('/api/v1/kpi-targets/plan')->assertForbidden();
    $this->actingAs($officer)->getJson('/api/v1/kpi-comparison?period=H2+2026')->assertForbidden();
    $this->actingAs($officer)->postJson('/api/v1/kpi-targets', [
        'kpi_definition_id' => $kpi->id, 'mission_id' => $london->id, 'performance_cycle_label' => 'H1 2026', 'target_value' => 5,
    ])->assertForbidden();
});

it('keeps KPI data from the four mission governance roles, which bypass ministry scoping (TC-FR-AUTH-017-KPI)', function (string $role) {
    $ministry = Ministry::factory()->create();
    $london = accessPosting($ministry, 'London');
    $kpi = accessKpi($ministry, 'Agreements');
    app(KpiService::class)->recordActual($kpi, $london, 'Q1 2026', Carbon::parse('2026-07-01'), 3, null, 'manual');
    $viewer = accessUser($role, null, $london);

    $this->actingAs($viewer)->getJson('/api/v1/kpi-actuals')->assertForbidden();
    $this->actingAs($viewer)->getJson('/api/v1/kpi-dashboard')->assertForbidden();
    $this->actingAs($viewer)->getJson('/api/v1/kpi-comparison')->assertForbidden();
    $this->actingAs($viewer)->getJson('/api/v1/kpi-targets/plan')->assertForbidden();
    $this->actingAs($viewer)->postJson('/api/v1/kpi-actuals', [
        'kpi_definition_id' => $kpi->id, 'mission_id' => $london->id, 'period_label' => 'Q1 2026', 'actual_value' => 9,
    ])->assertForbidden();
})->with(['Head of Mission', 'Deputy Head of Mission', 'MFA HQ Officer', 'MFA Principal Secretary']);

it('lets the target setters read KPI profiles but not manage them (TC-FR-KPI-003-B)', function () {
    $ministry = Ministry::factory()->create();
    $director = accessUser('Ministry HQ Director', $ministry);

    $this->actingAs($director)->getJson('/api/v1/kpi-profiles')->assertOk();
    $this->actingAs($director)->postJson('/api/v1/kpi-profiles', ['name' => 'Rogue', 'ministry_id' => $ministry->id])->assertForbidden();
    $this->actingAs(accessUser('Ministry Attache', $ministry, accessPosting($ministry, 'London')))->getJson('/api/v1/kpi-profiles')->assertForbidden();
});

it('lets the Ministry HQ Officer record and read actuals but not view the dashboards', function () {
    $ministry = Ministry::factory()->create();
    accessPosting($ministry, 'London');
    $officer = accessUser('Ministry HQ Officer', $ministry);

    $this->actingAs($officer)->getJson('/api/v1/kpi-actuals')->assertOk();
    $this->actingAs($officer)->getJson('/api/v1/kpi-actuals/entry')->assertOk();
    $this->actingAs($officer)->getJson('/api/v1/kpi-dashboard')->assertForbidden();
    $this->actingAs($officer)->getJson('/api/v1/kpi-targets/plan')->assertForbidden();
});

it('never shows or accepts another department\'s missions or KPIs (TC-NFR-SEC-006-KPI)', function () {
    $ministry = Ministry::factory()->create();
    $other = Ministry::factory()->create();
    $london = accessPosting($ministry, 'London');
    $foreignMission = accessPosting($other, 'Elsewhere');
    $foreignKpi = accessKpi($other, 'Foreign KPI');
    $ownKpi = accessKpi($ministry, 'Agreements');
    $director = accessUser('Ministry HQ Director', $ministry);

    $this->actingAs($director)->getJson("/api/v1/kpi-dashboard?mission_id={$foreignMission->id}")->assertNotFound();
    $this->actingAs($director)->getJson("/api/v1/kpi-targets/history?kpi_definition_id={$foreignKpi->id}&mission_id={$london->id}")->assertNotFound();
    $this->actingAs($director)->getJson("/api/v1/kpi-targets/history?kpi_definition_id={$ownKpi->id}&mission_id={$foreignMission->id}")->assertNotFound();
    $this->actingAs($director)->postJson('/api/v1/kpi-targets/batch', ['performance_cycle_label' => 'H1 2026', 'targets' => [
        ['kpi_definition_id' => $foreignKpi->id, 'mission_id' => $london->id, 'target_value' => 5],
    ]])->assertStatus(422);
    $this->actingAs($director)->get("/api/v1/kpi-reports/{$foreignMission->id}?period=H2+2026")->assertNotFound();

    $plan = $this->actingAs($director)->getJson('/api/v1/kpi-targets/plan')->assertOk();
    expect(collect($plan->json('data.missions'))->pluck('id')->all())->toBe([$london->id])
        ->and(collect($plan->json('data.kpis'))->pluck('id')->all())->toBe([$ownKpi->id]);

    $comparison = $this->actingAs($director)->getJson('/api/v1/kpi-comparison?period=H2+2026')->assertOk();
    expect(collect($comparison->json('data.missions'))->pluck('mission_id')->all())->toBe([$london->id]);

    $attache = accessUser('Ministry Attache', $ministry, $london);
    $this->actingAs($attache)->postJson('/api/v1/kpi-actuals', [
        'kpi_definition_id' => $foreignKpi->id, 'mission_id' => $london->id, 'period_label' => 'Q1 2026', 'actual_value' => 2,
    ])->assertNotFound();
});

it('pins an attache\'s actuals list to their own mission', function () {
    $ministry = Ministry::factory()->create();
    $london = accessPosting($ministry, 'London');
    $dubai = accessPosting($ministry, 'Dubai');
    $kpi = accessKpi($ministry, 'Agreements');
    $service = app(KpiService::class);
    $service->recordActual($kpi, $london, 'Q1 2026', Carbon::parse('2026-07-01'), 3, null, 'manual');
    $service->recordActual($kpi, $dubai, 'Q1 2026', Carbon::parse('2026-07-01'), 5, null, 'manual');
    $attache = accessUser('Ministry Attache', $ministry, $london);

    $rows = $this->actingAs($attache)->getJson('/api/v1/kpi-actuals')->assertOk()->json('data');
    expect(collect($rows)->pluck('mission.id')->unique()->values()->all())->toBe([$london->id]);

    $this->actingAs($attache)->getJson("/api/v1/kpi-actuals?mission_id={$dubai->id}")->assertForbidden();
});

it('refuses manual entry for a live KPI, another mission, or a closed, future or mislabelled quarter (TC-FR-KPI-007-B)', function (string $case) {
    $ministry = Ministry::factory()->create();
    $london = accessPosting($ministry, 'London');
    $dubai = accessPosting($ministry, 'Dubai');
    $attache = accessUser('Ministry Attache', $ministry, $london);
    $manual = accessKpi($ministry, 'Agreements');
    $live = accessKpi($ministry, 'Alerts submitted', 'auto', 'alerts.count_submitted');

    $payload = ['kpi_definition_id' => $manual->id, 'mission_id' => $london->id, 'period_label' => 'Q1 2026', 'actual_value' => 3];
    [$payload, $status] = match ($case) {
        'live KPI' => [[...$payload, 'kpi_definition_id' => $live->id], 422],
        'another mission' => [[...$payload, 'mission_id' => $dubai->id], 403],
        'future quarter' => [[...$payload, 'period_label' => 'Q3 2027'], 422],
        'closed quarter' => [[...$payload, 'period_label' => 'Q1 2025'], 422],
        'half-year label' => [[...$payload, 'period_label' => 'H1 2026'], 422],
        'mismatched start date' => [[...$payload, 'period_start_date' => '2026-01-01'], 422],
        'negative value' => [[...$payload, 'actual_value' => -1], 422],
    };

    $this->actingAs($attache)->postJson('/api/v1/kpi-actuals', $payload)->assertStatus($status);
    expect(KpiActual::query()->count())->toBe(0);
})->with(['live KPI', 'another mission', 'future quarter', 'closed quarter', 'half-year label', 'mismatched start date', 'negative value']);

it('accepts entry for the running quarter and the four before it, and lists the mission\'s KPIs (TC-FR-KPI-007-C)', function () {
    $ministry = Ministry::factory()->create();
    $london = accessPosting($ministry, 'London');
    $officer = accessUser('Ministry HQ Officer', $ministry);
    $manual = accessKpi($ministry, 'Agreements');
    accessKpi($ministry, 'Alerts submitted', 'auto', 'alerts.count_submitted');

    foreach (['Q2 2026', 'Q2 2025'] as $quarter) {
        $this->actingAs($officer)->postJson('/api/v1/kpi-actuals', [
            'kpi_definition_id' => $manual->id, 'mission_id' => $london->id, 'period_label' => $quarter, 'actual_value' => 2.5,
        ])->assertCreated();
    }

    $entry = $this->actingAs($officer)->getJson("/api/v1/kpi-actuals/entry?mission_id={$london->id}&period_label=Q2+2026")->assertOk();
    $kpis = collect($entry->json('data.kpis'))->keyBy('name');

    expect($entry->json('data.quarter.label'))->toBe('Q2 2026')
        ->and(collect($entry->json('data.quarters'))->pluck('label')->all())->toBe(['Q2 2026', 'Q1 2026', 'Q4 2026', 'Q3 2026', 'Q2 2025'])
        ->and($kpis['Agreements']['recordable'])->toBeTrue()
        ->and($kpis['Agreements']['value'])->toEqual(2.5)
        ->and($kpis['Agreements']['entered_by']['id'])->toBe($officer->id)
        ->and($kpis['Alerts submitted']['recordable'])->toBeFalse()
        ->and($kpis['Alerts submitted']['value'])->toEqual(0.0);
});
