<?php

use App\Models\Alert;
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
use App\Support\KpiPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * FR-KPI-006, 008, 009, 012, 016 — the KPI dashboard's numbers, computed by
 * App\Services\KpiPerformanceService. The clock is 2 November 2026: H1 2026
 * (Jul–Dec 2026) is running, its Q1 settled on 15 October and Q2 in
 * progress; H2 2026 (Jan–Jun 2026) is final.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-11-02');
});

afterEach(function () {
    Carbon::setTestNow();
});

function perfRole(string $name, string $layer = '2', string $scope = 'ministry'): Role
{
    return Role::query()->firstOrCreate(['name' => $name], ['layer' => $layer, 'scope' => $scope]);
}

function perfUser(string $role, Ministry $ministry, ?Mission $mission = null): User
{
    return User::factory()->create([
        'role_id' => perfRole($role)->id,
        'ministry_id' => $ministry->id,
        'mission_id' => $mission?->id,
    ]);
}

function perfPosting(Ministry $ministry, string $name): Mission
{
    $mission = Mission::factory()->create(['name' => $name]);
    MissionMinistryLink::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id]);

    return $mission;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function perfKpi(Ministry $ministry, string $name, array $attributes = []): KpiDefinition
{
    return KpiDefinition::factory()->create([
        'ministry_id' => $ministry->id,
        'name' => $name,
        'calculation_method' => 'manual',
        'data_source' => null,
        'active' => true,
        ...$attributes,
    ]);
}

function perfTarget(KpiDefinition $kpi, Mission $mission, string $cycle, float $value): KpiTarget
{
    $start = KpiPeriod::fromLabel($cycle)->start();

    return app(KpiService::class)->setTarget($kpi, $mission, $cycle, Carbon::instance($start), $value, User::factory()->create());
}

function perfActual(KpiDefinition $kpi, Mission $mission, string $quarter, float $value): KpiActual
{
    $start = KpiPeriod::fromLabel($quarter)->start();

    return app(KpiService::class)->recordActual($kpi, $mission, $quarter, Carbon::instance($start), $value, null, 'manual');
}

/**
 * @return array<string, array<string, mixed>> dashboard KPI rows keyed by KPI name
 */
function perfDashboardRows(TestResponse $response): array
{
    return collect($response->assertOk()->json('data.kpis'))->keyBy('name')->all();
}

it('judges a final cycle against its full target at the default thresholds (TC-FR-KPI-008-A)', function () {
    $ministry = Ministry::factory()->create();
    $london = perfPosting($ministry, 'London');
    $director = perfUser('Ministry HQ Director', $ministry);

    $values = ['Met' => [6, 4], 'Close' => [4, 3.5], 'Short' => [4, 3], 'Silent' => null];
    foreach ($values as $name => $quarters) {
        $kpi = perfKpi($ministry, $name);
        perfTarget($kpi, $london, 'H2 2026', 10);
        if ($quarters !== null) {
            perfActual($kpi, $london, 'Q3 2026', $quarters[0]);
            perfActual($kpi, $london, 'Q4 2026', $quarters[1]);
        }
    }
    perfKpi($ministry, 'Untargeted');

    $response = $this->actingAs($director)->getJson("/api/v1/kpi-dashboard?mission_id={$london->id}&period=H2+2026");
    $rows = perfDashboardRows($response);

    expect($response->json('data.period.is_final'))->toBeTrue()
        ->and($rows['Met']['status'])->toBe('on_track')
        ->and($rows['Met']['attainment'])->toEqual(1.0)
        ->and($rows['Close']['status'])->toBe('at_risk')
        ->and($rows['Close']['actual'])->toEqual(7.5)
        ->and($rows['Close']['variance'])->toEqual(-2.5)
        ->and($rows['Close']['attainment'])->toEqual(0.75)
        ->and($rows['Short']['status'])->toBe('below_target')
        ->and($rows['Silent']['status'])->toBe('no_data')
        ->and($rows['Silent']['actual'])->toBeNull()
        ->and($rows['Untargeted']['status'])->toBe('no_target')
        ->and($response->json('data.summary.counts'))->toMatchArray(['on_track' => 1, 'at_risk' => 1, 'below_target' => 1, 'no_data' => 1, 'no_target' => 1])
        ->and($response->json('data.summary.judged'))->toBe(3);
});

it('judges a running cycle against the share of its target settled so far (TC-FR-KPI-008-B)', function () {
    $ministry = Ministry::factory()->create();
    $london = perfPosting($ministry, 'London');
    $director = perfUser('Ministry HQ Director', $ministry);

    foreach (['Pace' => 5, 'Lagging' => 4, 'Behind' => 3, 'Done' => 10] as $name => $q1) {
        $kpi = perfKpi($ministry, $name);
        perfTarget($kpi, $london, 'H1 2026', 10);
        perfActual($kpi, $london, 'Q1 2026', $q1);
    }

    $rows = perfDashboardRows($this->actingAs($director)->getJson("/api/v1/kpi-dashboard?mission_id={$london->id}&period=H1+2026"));

    expect($rows['Pace']['expected'])->toEqual(5.0)
        ->and($rows['Pace']['status'])->toBe('on_track')
        ->and($rows['Lagging']['status'])->toBe('at_risk')
        ->and($rows['Behind']['status'])->toBe('below_target')
        ->and($rows['Done']['status'])->toBe('on_track');

    // Before Q1's 15 October deadline nothing has settled: too early to
    // judge, unless the whole target is already met.
    Carbon::setTestNow('2026-10-10');
    $early = perfDashboardRows($this->actingAs($director)->getJson("/api/v1/kpi-dashboard?mission_id={$london->id}&period=H1+2026"));

    expect($early['Pace']['status'])->toBe('pending')
        ->and($early['Behind']['status'])->toBe('pending')
        ->and($early['Done']['status'])->toBe('on_track')
        ->and($early['Pace']['expected'])->toEqual(0.0);
});

it('uses the configured thresholds (TC-FR-KPI-008-C)', function () {
    config(['kpi.thresholds.on_track' => 0.9, 'kpi.thresholds.at_risk' => 0.5]);
    $ministry = Ministry::factory()->create();
    $london = perfPosting($ministry, 'London');
    $director = perfUser('Ministry PS', $ministry);

    foreach (['Ninety' => 9, 'Half' => 5, 'Forty' => 4] as $name => $value) {
        $kpi = perfKpi($ministry, $name);
        perfTarget($kpi, $london, 'H2 2026', 10);
        perfActual($kpi, $london, 'Q3 2026', $value);
    }

    $response = $this->actingAs($director)->getJson("/api/v1/kpi-dashboard?mission_id={$london->id}&period=H2+2026");
    $rows = perfDashboardRows($response);

    expect($rows['Ninety']['status'])->toBe('on_track')
        ->and($rows['Half']['status'])->toBe('at_risk')
        ->and($rows['Forty']['status'])->toBe('below_target')
        ->and($response->json('data.thresholds'))->toEqual(['on_track' => 0.9, 'at_risk' => 0.5]);
});

it('calculates an auto KPI live from engine data, with no stored actual (TC-FR-KPI-006-A)', function () {
    $ministry = Ministry::factory()->create();
    $london = perfPosting($ministry, 'London');
    $dubai = perfPosting($ministry, 'Dubai');
    $director = perfUser('Ministry HQ Director', $ministry);
    $kpi = perfKpi($ministry, 'Alerts submitted', ['calculation_method' => 'auto', 'data_source' => 'alerts.count_submitted']);
    perfTarget($kpi, $london, 'H1 2026', 8);

    Alert::factory()->count(3)->create(['ministry_id' => $ministry->id, 'mission_id' => $london->id, 'created_at' => '2026-08-04 10:00:00']);
    Alert::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $london->id, 'created_at' => '2026-06-30 23:00:00']);
    Alert::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $dubai->id, 'created_at' => '2026-08-04 10:00:00']);
    Alert::factory()->create(['ministry_id' => Ministry::factory()->create()->id, 'mission_id' => $london->id, 'created_at' => '2026-08-04 10:00:00']);

    $uri = "/api/v1/kpi-dashboard?mission_id={$london->id}&period=H1+2026";
    $row = perfDashboardRows($this->actingAs($director)->getJson($uri))['Alerts submitted'];

    expect($row['source'])->toBe('live')
        ->and($row['actual'])->toEqual(3.0)
        ->and(collect($row['quarters'])->pluck('actual', 'label')->all())->toEqual(['Q1 2026' => 3.0, 'Q2 2026' => 0.0])
        ->and(KpiActual::query()->count())->toBe(0);

    Alert::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $london->id, 'created_at' => '2026-11-01 09:00:00']);

    expect(perfDashboardRows($this->actingAs($director)->getJson($uri))['Alerts submitted']['actual'])->toEqual(4.0);
});

it('counts reports by the quarter they cover, and on-time ones separately (TC-FR-KPI-012-A)', function () {
    $ministry = Ministry::factory()->create();
    $london = perfPosting($ministry, 'London');
    $director = perfUser('Ministry HQ Director', $ministry);
    $submitted = perfKpi($ministry, 'Reports submitted', ['calculation_method' => 'auto', 'data_source' => 'reports.count_submitted']);
    $onTime = perfKpi($ministry, 'Reports on time', ['calculation_method' => 'auto', 'data_source' => 'reports.count_submitted_on_time']);
    perfTarget($submitted, $london, 'H2 2026', 2);
    perfTarget($onTime, $london, 'H2 2026', 2);

    PeriodicReport::factory()->create([
        'ministry_id' => $ministry->id, 'mission_id' => $london->id, 'reporting_period_label' => 'Q3 2026',
        'period_start_date' => '2026-01-01', 'period_end_date' => '2026-03-31',
        'status' => 'submitted', 'submitted_at' => '2026-04-10 09:00:00', 'is_late' => false,
    ]);
    PeriodicReport::factory()->create([
        'ministry_id' => $ministry->id, 'mission_id' => $london->id, 'reporting_period_label' => 'Q4 2026',
        'period_start_date' => '2026-04-01', 'period_end_date' => '2026-06-30',
        'status' => 'submitted', 'submitted_at' => '2026-07-20 09:00:00', 'is_late' => true,
    ]);

    $response = $this->actingAs($director)->getJson("/api/v1/kpi-dashboard?mission_id={$london->id}&period=H2+2026");
    $rows = perfDashboardRows($response);

    expect($rows['Reports submitted']['actual'])->toEqual(2.0)
        ->and($rows['Reports submitted']['status'])->toBe('on_track')
        ->and($rows['Reports on time']['actual'])->toEqual(1.0)
        ->and($rows['Reports on time']['status'])->toBe('below_target');

    $compliance = collect($response->json('data.compliance'))->keyBy('label');
    expect($compliance['Q3 2026']['status'])->toBe('submitted_on_time')
        ->and($compliance['Q4 2026']['status'])->toBe('submitted_late')
        ->and($compliance['Q4 2026']['report_id'])->not->toBeNull();
});

it('rolls quarters up into the half-year and splits the cycle target across its quarters (TC-FR-KPI-016-E)', function () {
    $ministry = Ministry::factory()->create();
    $london = perfPosting($ministry, 'London');
    $ps = perfUser('Ministry PS', $ministry);
    $kpi = perfKpi($ministry, 'Trade briefs');
    perfTarget($kpi, $london, 'H2 2026', 10);
    perfActual($kpi, $london, 'Q3 2026', 4);
    perfActual($kpi, $london, 'Q4 2026', 3);

    $half = perfDashboardRows($this->actingAs($ps)->getJson("/api/v1/kpi-dashboard?mission_id={$london->id}&period=H2+2026"))['Trade briefs'];
    $quarter = perfDashboardRows($this->actingAs($ps)->getJson("/api/v1/kpi-dashboard?mission_id={$london->id}&period=Q3+2026"))['Trade briefs'];

    expect($half['actual'])->toEqual(7.0)
        ->and($half['target'])->toEqual(10.0)
        ->and($half['quarters_reported'])->toBe(2)
        ->and($quarter['target'])->toEqual(5.0)
        ->and($quarter['actual'])->toEqual(4.0)
        ->and($quarter['status'])->toBe('at_risk')
        ->and(collect($half['trend'])->firstWhere('label', 'Q4 2026')['target'])->toEqual(5.0);
});

it('measures a custom range of whole quarters (TC-FR-KPI-009-A)', function () {
    $ministry = Ministry::factory()->create();
    $london = perfPosting($ministry, 'London');
    $director = perfUser('Ministry HQ Director', $ministry);
    $kpi = perfKpi($ministry, 'Agreements');
    perfTarget($kpi, $london, 'H1 2025', 4);
    perfTarget($kpi, $london, 'H2 2026', 6);
    foreach (['Q1 2025' => 1, 'Q2 2025' => 2, 'Q3 2026' => 3, 'Q4 2026' => 2] as $quarter => $value) {
        perfActual($kpi, $london, $quarter, $value);
    }

    $response = $this->actingAs($director)->getJson("/api/v1/kpi-dashboard?mission_id={$london->id}&from=2025-07-10&to=2026-06-01");
    $row = perfDashboardRows($response)['Agreements'];

    expect($response->json('data.period.type'))->toBe('range')
        ->and($response->json('data.period.label'))->toBe('Q1 2025 – Q4 2026')
        ->and($row['target'])->toEqual(10.0)
        ->and($row['actual'])->toEqual(8.0)
        ->and($row['status'])->toBe('at_risk');
});

it('rejects an invalid period with a 422 instead of failing (TC-FR-KPI-009-B)', function (string $query) {
    $ministry = Ministry::factory()->create();
    perfPosting($ministry, 'London');
    $director = perfUser('Ministry HQ Director', $ministry);

    $this->actingAs($director)->getJson("/api/v1/kpi-dashboard?{$query}")->assertStatus(422);
    $this->actingAs($director)->getJson("/api/v1/kpi-comparison?{$query}")->assertStatus(422);
})->with([
    'unknown quarter' => ['period=Q9+2026'],
    'free text' => ['period=last+year'],
    'range without an end' => ['from=2026-01-01'],
    'reversed range' => ['from=2026-06-01&to=2025-07-01'],
    'range into the future' => ['from=2026-07-01&to=2027-03-31'],
    'range too long' => ['from=2022-07-01&to=2026-06-30'],
    'not a date' => ['from=yesterday-ish&to=2026-06-30'],
]);

it('also answers the old cycle_label parameter with a 422 for a malformed label', function () {
    $ministry = Ministry::factory()->create();
    $director = perfUser('Ministry HQ Director', $ministry);

    $this->actingAs($director)->getJson('/api/v1/kpi-comparison?cycle_label=foo')->assertStatus(422);
    $this->actingAs(perfUser('HRM&D Officer', $ministry))->getJson('/api/v1/sdt/hrmd-dashboard?cycle_label=foo')->assertStatus(422);
});

it('compares each KPI with the previous period (TC-FR-KPI-010-B)', function () {
    $ministry = Ministry::factory()->create();
    $london = perfPosting($ministry, 'London');
    $director = perfUser('Ministry HQ Director', $ministry);
    $kpi = perfKpi($ministry, 'Agreements');
    perfActual($kpi, $london, 'Q1 2025', 2);
    perfActual($kpi, $london, 'Q2 2025', 2);
    perfActual($kpi, $london, 'Q3 2026', 3);
    perfActual($kpi, $london, 'Q4 2026', 3);

    $response = $this->actingAs($director)->getJson("/api/v1/kpi-dashboard?mission_id={$london->id}&period=H2+2026");
    $row = perfDashboardRows($response)['Agreements'];

    expect($response->json('data.previous_period.label'))->toBe('H1 2025')
        ->and($row['previous_actual'])->toEqual(4.0)
        ->and($row['change'])->toEqual(2.0)
        ->and($row['change_pct'])->toEqual(0.5);
});

it('opens on the half-year holding the latest settled quarter (TC-FR-KPI-016-F)', function () {
    $ministry = Ministry::factory()->create();
    $london = perfPosting($ministry, 'London');
    $director = perfUser('Ministry HQ Director', $ministry);

    expect($this->actingAs($director)->getJson("/api/v1/kpi-dashboard?mission_id={$london->id}")->json('data.period.label'))->toBe('H1 2026');

    Carbon::setTestNow('2026-10-05');
    expect($this->actingAs($director)->getJson("/api/v1/kpi-dashboard?mission_id={$london->id}")->json('data.period.label'))->toBe('H2 2026');
});

it('totals each KPI across the department and ranks missions weakest first (TC-FR-KPI-013-B)', function () {
    $ministry = Ministry::factory()->create();
    $london = perfPosting($ministry, 'London');
    $dubai = perfPosting($ministry, 'Dubai');
    $closed = perfPosting($ministry, 'Closed Post');
    $closed->update(['active' => false]);
    $foreign = perfPosting(Ministry::factory()->create(), 'Elsewhere');
    $director = perfUser('Ministry HQ Director', $ministry);
    $kpi = perfKpi($ministry, 'Agreements');

    perfTarget($kpi, $london, 'H2 2026', 10);
    perfTarget($kpi, $dubai, 'H2 2026', 10);
    perfActual($kpi, $london, 'Q3 2026', 10);
    perfActual($kpi, $dubai, 'Q3 2026', 4);

    $response = $this->actingAs($director)->getJson('/api/v1/kpi-dashboard?period=H2+2026');
    $row = perfDashboardRows($response)['Agreements'];

    expect($response->json('data.scope'))->toBe('ministry')
        ->and($row['target'])->toEqual(20.0)
        ->and($row['actual'])->toEqual(14.0)
        ->and($row['status'])->toBe('below_target')
        ->and($row['missions_with_target'])->toBe(2)
        ->and($row['distribution'])->toMatchArray(['on_track' => 1, 'below_target' => 1])
        ->and(collect($response->json('data.missions'))->pluck('mission_name')->all())->toBe(['Dubai', 'London'])
        ->and(collect($response->json('data.missions_available'))->pluck('id')->all())->not->toContain($closed->id)->not->toContain($foreign->id);
});

it('lists the mission\'s report compliance per quarter, linking only submitted reports (TC-FR-KPI-012-B)', function () {
    $ministry = Ministry::factory()->create();
    $london = perfPosting($ministry, 'London');
    $director = perfUser('Ministry HQ Director', $ministry);
    perfKpi($ministry, 'Agreements');
    PeriodicReport::factory()->create([
        'ministry_id' => $ministry->id, 'mission_id' => $london->id, 'reporting_period_label' => 'Q1 2026',
        'period_start_date' => '2026-07-01', 'period_end_date' => '2026-09-30', 'status' => 'draft',
    ]);

    $compliance = collect($this->actingAs($director)->getJson("/api/v1/kpi-dashboard?mission_id={$london->id}&period=H1+2026")->json('data.compliance'))->keyBy('label');

    expect($compliance['Q1 2026']['status'])->toBe('draft_in_progress')
        ->and($compliance['Q1 2026']['is_overdue'])->toBeTrue()
        ->and($compliance['Q1 2026']['report_id'])->toBeNull()
        ->and($compliance['Q2 2026']['status'])->toBe('not_due');
});
