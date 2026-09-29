<?php

use App\Enums\AlertStatus;
use App\Enums\DirectiveStatus;
use App\Enums\InquiryStatus;
use App\Enums\PeriodicReportStatus;
use App\Models\Alert;
use App\Models\Directive;
use App\Models\Inquiry;
use App\Models\KpiActual;
use App\Models\KpiDefinition;
use App\Models\KpiTarget;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\Notification;
use App\Models\PeriodicReport;
use App\Models\ReportSection;
use App\Models\Role;
use App\Models\User;
use App\Services\KpiService;
use Illuminate\Support\Carbon;

/**
 * GET /api/v1/dashboard (App\Services\DashboardService): the role-shaped
 * home dashboard. Pinned to 29 September 2026 so the fiscal labels are
 * fixed: quarter in progress Q1 2026 (Jul-Sep 2026), report deadline
 * 15 October 2026, latest completed KPI cycle H2 2026 (Jan-Jun 2026).
 */
function dashboardUser(string $roleName, Ministry $ministry, ?Mission $mission = null): User
{
    $role = Role::query()->firstOrCreate(['name' => $roleName], ['layer' => '2', 'scope' => $mission ? 'mission' : 'ministry']);

    return User::factory()->create(['role_id' => $role->id, 'ministry_id' => $ministry->id, 'mission_id' => $mission?->id]);
}

function dashboardKpiActual(KpiDefinition $kpi, Mission $mission, string $periodLabel, string $periodStart, float $value): KpiActual
{
    return KpiActual::factory()->create([
        'kpi_definition_id' => $kpi->id,
        'mission_id' => $mission->id,
        'period_label' => $periodLabel,
        'period_start_date' => $periodStart,
        'actual_value' => $value,
    ]);
}

function dashboardKpiTarget(KpiDefinition $kpi, Mission $mission, string $cycleLabel, float $value): KpiTarget
{
    return KpiTarget::factory()->create([
        'kpi_definition_id' => $kpi->id,
        'mission_id' => $mission->id,
        'performance_cycle_label' => $cycleLabel,
        'target_value' => $value,
    ]);
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-29 10:00:00'));
});

// --- Ministry Attache -----------------------------------------------------

it('shows an attache their own mission\'s KPIs against target, with the prior cycle alongside (TC-FR-KPI-010-A)', function () {
    $ministry = Ministry::factory()->create();
    $ownMission = Mission::factory()->create();
    $otherMission = Mission::factory()->create();
    $attache = dashboardUser('Ministry Attache', $ministry, $ownMission);
    $kpi = KpiDefinition::factory()->create(['ministry_id' => $ministry->id, 'name' => 'Trade briefs submitted']);

    dashboardKpiTarget($kpi, $ownMission, 'H2 2026', 10);
    dashboardKpiActual($kpi, $ownMission, 'Q3 2026', '2026-01-01', 6);
    dashboardKpiActual($kpi, $ownMission, 'Q4 2026', '2026-04-01', 5);
    dashboardKpiActual($kpi, $ownMission, 'Q1 2025', '2025-07-01', 4);
    dashboardKpiTarget($kpi, $otherMission, 'H2 2026', 99);
    dashboardKpiActual($kpi, $otherMission, 'Q3 2026', '2026-01-01', 50);

    $response = $this->actingAs($attache)->getJson('/api/v1/dashboard');

    $response->assertOk();
    expect($response->json('data.view'))->toBe('attache')
        ->and($response->json('data.kpi.cycle.label'))->toBe('H2 2026')
        ->and($response->json('data.kpi.previous_cycle.label'))->toBe('H1 2025')
        ->and($response->json('data.kpi.kpis'))->toHaveCount(1)
        ->and($response->json('data.kpi.kpis.0.target'))->toEqual(10)
        ->and($response->json('data.kpi.kpis.0.actual'))->toEqual(11)
        ->and($response->json('data.kpi.kpis.0.status'))->toBe('on_track')
        ->and($response->json('data.kpi.kpis.0.previous_actual'))->toEqual(4);
});

it('lets an attache choose another KPI cycle and rejects a malformed one (TC-FR-KPI-010-B)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = dashboardUser('Ministry Attache', $ministry, $mission);
    $kpi = KpiDefinition::factory()->create(['ministry_id' => $ministry->id]);
    dashboardKpiActual($kpi, $mission, 'Q1 2025', '2025-07-01', 4);

    $response = $this->actingAs($attache)->getJson('/api/v1/dashboard?kpi_cycle='.urlencode('H1 2025'));

    $response->assertOk();
    expect($response->json('data.kpi.cycle.label'))->toBe('H1 2025')
        ->and($response->json('data.kpi.previous_cycle.label'))->toBe('H2 2025')
        ->and($response->json('data.kpi.kpis.0.actual'))->toEqual(4)
        ->and($response->json('data.kpi.kpis.0.status'))->toBe('no_target');

    $this->actingAs($attache)->getJson('/api/v1/dashboard?kpi_cycle='.urlencode('Q9 2026'))->assertStatus(422);
});

it('counts only the attache\'s own mission, by fiscal quarter (TC-BR-001)', function () {
    $ministry = Ministry::factory()->create();
    $ownMission = Mission::factory()->create();
    $otherMission = Mission::factory()->create();
    $attache = dashboardUser('Ministry Attache', $ministry, $ownMission);

    Alert::factory()->count(2)->create(['ministry_id' => $ministry->id, 'mission_id' => $ownMission->id]);
    Alert::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $ownMission->id, 'created_at' => '2026-05-10 09:00:00']);
    Alert::factory()->count(3)->create(['ministry_id' => $ministry->id, 'mission_id' => $otherMission->id]);
    Inquiry::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $ownMission->id, 'status' => InquiryStatus::InProgress->value, 'date_received' => '2026-08-01']);
    Inquiry::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $otherMission->id, 'status' => InquiryStatus::InProgress->value]);

    $response = $this->actingAs($attache)->getJson('/api/v1/dashboard');

    $response->assertOk();
    expect($response->json('data.alerts.this_quarter'))->toBe(2)
        ->and($response->json('data.alerts.previous_quarter'))->toBe(1)
        ->and($response->json('data.activity_trend'))->toHaveCount(8)
        ->and($response->json('data.activity_trend.7.label'))->toBe('Q1 2026')
        ->and($response->json('data.activity_trend.7.alerts'))->toBe(2)
        ->and($response->json('data.activity_trend.7.inquiries'))->toBe(1)
        ->and($response->json('data.inquiries.open'))->toBe(1)
        ->and($response->json('data.inquiries.pipeline.in_progress'))->toBe(1);
});

it('shows the open reporting period, its deadline and the attache\'s draft progress (TC-FR-RPT-003)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = dashboardUser('Ministry Attache', $ministry, $mission);

    $before = $this->actingAs($attache)->getJson('/api/v1/dashboard');

    expect($before->json('data.calendar.reporting_period.label'))->toBe('Q1 2026')
        ->and($before->json('data.calendar.reporting_period.deadline'))->toBe('2026-10-15')
        ->and($before->json('data.calendar.reporting_period.days_remaining'))->toBe(16)
        ->and($before->json('data.report.status'))->toBe('not_started');

    $report = PeriodicReport::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'reporting_period_label' => 'Q1 2026',
        'period_start_date' => '2026-07-01',
        'period_end_date' => '2026-09-30',
        'status' => PeriodicReportStatus::Draft->value,
    ]);
    ReportSection::factory()->create(['periodic_report_id' => $report->id, 'content' => 'Market overview drafted.']);
    ReportSection::factory()->create(['periodic_report_id' => $report->id, 'content' => null]);

    $after = $this->actingAs($attache)->getJson('/api/v1/dashboard');

    expect($after->json('data.report.status'))->toBe('draft')
        ->and($after->json('data.report.id'))->toBe($report->id)
        ->and($after->json('data.report.sections_total'))->toBe(2)
        ->and($after->json('data.report.sections_drafted'))->toBe(1);
});

it('lists the attache\'s open directives and flags the overdue ones (TC-FR-DIR-012)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = dashboardUser('Ministry Attache', $ministry, $mission);

    Directive::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id, 'target_user_id' => $attache->id, 'status' => DirectiveStatus::Issued->value, 'target_completion_date' => '2026-09-01']);
    Directive::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id, 'target_user_id' => $attache->id, 'status' => DirectiveStatus::InProgress->value, 'target_completion_date' => '2026-11-01']);
    Directive::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id, 'target_user_id' => $attache->id, 'status' => DirectiveStatus::Completed->value, 'target_completion_date' => '2026-08-01']);

    $response = $this->actingAs($attache)->getJson('/api/v1/dashboard');

    expect($response->json('data.directives.open'))->toBe(2)
        ->and($response->json('data.directives.overdue'))->toBe(1)
        ->and($response->json('data.directives.items.0.is_overdue'))->toBeTrue()
        ->and($response->json('data.directives.items.0.target_completion_date'))->toBe('2026-09-01');
});

// --- Leadership: Ministry PS / Acting PS / Ministry HQ Director ------------

it('gives a Ministry PS the executive view with the routed-alert inbox (TC-FR-SDT-001)', function () {
    $ministry = Ministry::factory()->create();
    $ps = dashboardUser('Ministry PS', $ministry);

    Alert::factory()->count(2)->create(['ministry_id' => $ministry->id, 'status' => AlertStatus::New->value, 'intelligence_type' => 'opportunities']);
    Alert::factory()->create(['ministry_id' => $ministry->id, 'status' => AlertStatus::Acknowledged->value, 'intelligence_type' => 'trade_barriers']);

    $response = $this->actingAs($ps)->getJson('/api/v1/dashboard');

    $response->assertOk();
    expect($response->json('data.view'))->toBe('leadership')
        ->and($response->json('data.variant'))->toBe('executive')
        ->and($response->json('data.alert_inbox'))->toHaveCount(2)
        ->and($response->json('data.alerts.awaiting_action'))->toBe(2)
        ->and($response->json('data.alerts.this_quarter'))->toBe(3)
        ->and($response->json('data.alert_trend.7.opportunities'))->toBe(2)
        ->and($response->json('data.alert_trend.7.trade_barriers'))->toBe(1);
});

it('gives the Acting PS the same executive view as the PS (TC-FR-SDT-004)', function () {
    $actingPs = dashboardUser('Acting PS', Ministry::factory()->create());

    $response = $this->actingAs($actingPs)->getJson('/api/v1/dashboard');

    expect($response->json('data.variant'))->toBe('executive')
        ->and($response->json('data'))->toHaveKey('alert_inbox');
});

it('gives a Ministry HQ Director the director view, without the PS inbox', function () {
    $director = dashboardUser('Ministry HQ Director', Ministry::factory()->create());

    $response = $this->actingAs($director)->getJson('/api/v1/dashboard');

    $response->assertOk();
    expect($response->json('data.variant'))->toBe('director')
        ->and($response->json('data'))->not->toHaveKey('alert_inbox');
});

it('summarises KPI health per mission with the same statuses as the comparison matrix (TC-FR-KPI-013)', function () {
    $ministry = Ministry::factory()->create();
    $strongMission = Mission::factory()->create(['name' => 'Accra']);
    $weakMission = Mission::factory()->create(['name' => 'Berlin']);
    MissionMinistryLink::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $strongMission->id]);
    MissionMinistryLink::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $weakMission->id]);
    $director = dashboardUser('Ministry HQ Director', $ministry);

    $briefs = KpiDefinition::factory()->create(['ministry_id' => $ministry->id]);
    $forums = KpiDefinition::factory()->create(['ministry_id' => $ministry->id]);

    foreach ([$strongMission, $weakMission] as $mission) {
        dashboardKpiTarget($briefs, $mission, 'H2 2026', 10);
        dashboardKpiTarget($forums, $mission, 'H2 2026', 4);
    }
    dashboardKpiActual($briefs, $strongMission, 'Q3 2026', '2026-01-01', 6);
    dashboardKpiActual($briefs, $strongMission, 'Q4 2026', '2026-04-01', 6);
    dashboardKpiActual($forums, $strongMission, 'Q3 2026', '2026-01-01', 3);
    dashboardKpiActual($briefs, $weakMission, 'Q3 2026', '2026-01-01', 2);

    $response = $this->actingAs($director)->getJson('/api/v1/dashboard');
    $rows = collect($response->json('data.kpi.missions'))->keyBy('mission_name');

    expect($response->json('data.kpi.cycle.label'))->toBe('H2 2026')
        ->and($response->json('data.kpi.missions.0.mission_name'))->toBe('Berlin')
        ->and($rows['Accra']['counts'])->toMatchArray(['on_track' => 1, 'at_risk' => 1, 'below_target' => 0, 'no_data' => 0])
        ->and($rows['Berlin']['counts'])->toMatchArray(['on_track' => 0, 'below_target' => 1, 'no_data' => 1]);

    $matrix = app(KpiService::class)->buildComparisonMatrix($ministry->id, 'H2 2026');

    foreach ($matrix['missions'] as $missionRow) {
        $expected = collect($missionRow['kpis'])->countBy('status')->all();
        expect(array_filter($rows[$missionRow['mission_name']]['counts']))->toEqual($expected);
    }
});

it('reports each recent period\'s on-time, late and missing reports, flagging the open one (TC-FR-RPT-016)', function () {
    $ministry = Ministry::factory()->create();
    $missions = Mission::factory()->count(2)->create();
    $missions->each(fn (Mission $mission) => MissionMinistryLink::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id]));
    $director = dashboardUser('Ministry HQ Director', $ministry);

    PeriodicReport::factory()->create([
        'ministry_id' => $ministry->id, 'mission_id' => $missions[0]->id, 'reporting_period_label' => 'Q4 2026',
        'period_start_date' => '2026-04-01', 'period_end_date' => '2026-06-30',
        'status' => PeriodicReportStatus::Submitted->value, 'submitted_at' => '2026-07-10', 'is_late' => false,
    ]);
    PeriodicReport::factory()->create([
        'ministry_id' => $ministry->id, 'mission_id' => $missions[1]->id, 'reporting_period_label' => 'Q4 2026',
        'period_start_date' => '2026-04-01', 'period_end_date' => '2026-06-30',
        'status' => PeriodicReportStatus::Submitted->value, 'submitted_at' => '2026-07-20', 'is_late' => true,
    ]);

    $trend = collect($this->actingAs($director)->getJson('/api/v1/dashboard')->json('data.reports.trend'));

    expect($trend)->toHaveCount(6)
        ->and($trend->firstWhere('label', 'Q4 2026'))->toMatchArray(['on_time' => 1, 'late' => 1, 'missing' => 0, 'is_open' => false])
        ->and($trend->last())->toMatchArray(['label' => 'Q1 2026', 'is_open' => true, 'missing' => 2]);
});

// --- Ministry HQ Officer --------------------------------------------------

it('shows an HQ Officer the alerts delegated to them and how old the open inquiries are (TC-FR-SDT-012)', function () {
    $ministry = Ministry::factory()->create();
    $officer = dashboardUser('Ministry HQ Officer', $ministry);
    $colleague = dashboardUser('Ministry HQ Officer', $ministry);

    Alert::factory()->create(['ministry_id' => $ministry->id, 'assigned_to_user_id' => $officer->id, 'status' => AlertStatus::Assigned->value]);
    Alert::factory()->create(['ministry_id' => $ministry->id, 'assigned_to_user_id' => $officer->id, 'status' => AlertStatus::Acknowledged->value]);
    Alert::factory()->create(['ministry_id' => $ministry->id, 'assigned_to_user_id' => $colleague->id, 'status' => AlertStatus::Assigned->value]);
    Inquiry::factory()->create(['ministry_id' => $ministry->id, 'status' => InquiryStatus::InProgress->value, 'date_received' => '2026-06-01', 'category' => 'Buyer Seeking Supplier']);
    Inquiry::factory()->create(['ministry_id' => $ministry->id, 'status' => InquiryStatus::Received->value, 'date_received' => '2026-09-27', 'category' => 'Buyer Seeking Supplier']);

    $response = $this->actingAs($officer)->getJson('/api/v1/dashboard');
    $age = collect($response->json('data.inquiries.age'))->pluck('count', 'key');

    expect($response->json('data.view'))->toBe('hq_officer')
        ->and($response->json('data.alerts.awaiting_me'))->toBe(1)
        ->and($response->json('data.alerts.acknowledged_by_me'))->toBe(1)
        ->and($age['under_7_days'])->toBe(1)
        ->and($age['over_90_days'])->toBe(1)
        ->and($response->json('data.inquiries.categories.0'))->toBe(['name' => 'Buyer Seeking Supplier', 'count' => 2]);
});

// --- Everyone else --------------------------------------------------------

it('shows other ministry roles the alerts routed to them and their unread notifications', function () {
    $ministry = Ministry::factory()->create();
    $deputy = dashboardUser('Designated Deputy', $ministry);

    Alert::factory()->create(['ministry_id' => $ministry->id, 'assigned_to_user_id' => $deputy->id, 'status' => AlertStatus::New->value]);
    Notification::factory()->create(['recipient_user_id' => $deputy->id]);
    Notification::factory()->create(['recipient_user_id' => $deputy->id, 'read_at' => now()]);

    $response = $this->actingAs($deputy)->getJson('/api/v1/dashboard');

    expect($response->json('data.view'))->toBe('general')
        ->and($response->json('data.assigned_alerts.count'))->toBe(1)
        ->and($response->json('data.notifications.unread'))->toBe(1);
});

it('turns away the roles that have dashboards of their own (TC-FR-SDT-018, TC-BR-025)', function (string $roleName) {
    $user = dashboardUser($roleName, Ministry::factory()->create());

    $this->actingAs($user)->getJson('/api/v1/dashboard')->assertForbidden();
})->with(['HRM&D Officer', 'Ministry Administrator']);

it('requires authentication', function () {
    $this->getJson('/api/v1/dashboard')->assertUnauthorized();
});
