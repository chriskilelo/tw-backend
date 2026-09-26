<?php

use App\Models\Alert;
use App\Models\Directive;
use App\Models\Inquiry;
use App\Models\KpiDefinition;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\PeriodicReport;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * CLAUDE.md Section 4, Rule 1 / NFR-SEC-006: every Layer 2 query must be
 * scoped to the authenticated user's ministry via the global Eloquent scope,
 * never left to controller or frontend filtering.
 */
function registerMinistryScopeTestRoute(): void
{
    Route::middleware(['ministry.scope'])->get('/__test/alerts/{id}', function (string $id) {
        $alert = Alert::findOrFail($id);

        return response()->json(['data' => ['id' => $alert->id]]);
    });
}

it('does not return alerts belonging to a different ministry when queried (TC-NFR-SEC-006-A)', function () {
    $ownMinistry = Ministry::factory()->create();
    $otherMinistry = Ministry::factory()->create();

    $attacheRole = Role::factory()->create(['name' => 'Ministry Attache', 'layer' => '2', 'scope' => 'mission']);
    $attache = User::factory()->create(['role_id' => $attacheRole->id, 'ministry_id' => $ownMinistry->id]);

    Alert::factory()->create(['ministry_id' => $ownMinistry->id]);
    $foreignAlert = Alert::factory()->create(['ministry_id' => $otherMinistry->id]);

    $this->actingAs($attache);

    $alerts = Alert::all();

    expect($alerts)->toHaveCount(1);
    expect($alerts->pluck('id'))->not->toContain($foreignAlert->id);
});

it('returns 404 on direct UUID access to an alert belonging to a different ministry (TC-NFR-SEC-006-B)', function () {
    registerMinistryScopeTestRoute();

    $ownMinistry = Ministry::factory()->create();
    $otherMinistry = Ministry::factory()->create();

    $attacheRole = Role::factory()->create(['name' => 'Ministry Attache', 'layer' => '2', 'scope' => 'mission']);
    $attache = User::factory()->create(['role_id' => $attacheRole->id, 'ministry_id' => $ownMinistry->id]);

    $foreignAlert = Alert::factory()->create(['ministry_id' => $otherMinistry->id]);

    $this->actingAs($attache)
        ->getJson("/__test/alerts/{$foreignAlert->id}")
        ->assertNotFound();
});

it('lets System Administrator bypass ministry scoping entirely', function () {
    $ministryA = Ministry::factory()->create();
    $ministryB = Ministry::factory()->create();

    $adminRole = Role::factory()->create(['name' => 'System Administrator', 'layer' => '1', 'scope' => 'platform']);
    $admin = User::factory()->create(['role_id' => $adminRole->id, 'ministry_id' => null]);

    Alert::factory()->create(['ministry_id' => $ministryA->id]);
    Alert::factory()->create(['ministry_id' => $ministryB->id]);

    $this->actingAs($admin);

    expect(Alert::all())->toHaveCount(2);
});

function ministryIsolationAttache(Ministry $ministry, Mission $mission): User
{
    $role = Role::query()->firstOrCreate(['name' => 'Ministry Attache'], ['layer' => '2', 'scope' => 'mission']);

    return User::factory()->create([
        'role_id' => $role->id,
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
    ]);
}

it('never returns another ministry\'s inquiries through the real inquiries endpoint (TC-NFR-SEC-006-A, inquiries)', function () {
    $ownMinistry = Ministry::factory()->create();
    $otherMinistry = Ministry::factory()->create();
    $ownMission = Mission::factory()->create();
    $otherMission = Mission::factory()->create();
    $attache = ministryIsolationAttache($ownMinistry, $ownMission);

    Inquiry::factory()->create(['ministry_id' => $ownMinistry->id, 'mission_id' => $ownMission->id]);
    $foreignInquiry = Inquiry::factory()->create(['ministry_id' => $otherMinistry->id, 'mission_id' => $otherMission->id]);

    $response = $this->actingAs($attache)->getJson('/api/v1/inquiries');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect(collect($response->json('data'))->pluck('id'))->not->toContain($foreignInquiry->id);
});

it('returns 404 on direct UUID access to an inquiry belonging to a different ministry through the real endpoint (TC-NFR-SEC-006-B, inquiries)', function () {
    $ownMinistry = Ministry::factory()->create();
    $otherMinistry = Ministry::factory()->create();
    $ownMission = Mission::factory()->create();
    $otherMission = Mission::factory()->create();
    $attache = ministryIsolationAttache($ownMinistry, $ownMission);

    $foreignInquiry = Inquiry::factory()->create(['ministry_id' => $otherMinistry->id, 'mission_id' => $otherMission->id]);

    $this->actingAs($attache)
        ->getJson("/api/v1/inquiries/{$foreignInquiry->id}")
        ->assertNotFound();
});

/**
 * The Directive and Periodic Report engines (CLAUDE.md Section 11's
 * DirectiveService/ReportService) have no HTTP controllers or routes yet —
 * only their models, migrations, and HasMinistryScope wiring exist (see
 * CLAUDE.md's Session 10-15 notes; no session has built these two engines'
 * Api\* controllers). NFR-SEC-006 is still meaningfully testable today at
 * the level CLAUDE.md Section 4 Rule 1 actually specifies the guarantee —
 * the global Eloquent scope — via direct model queries, exactly like the
 * pre-existing Alert-only test above did before the real Alert HTTP
 * endpoints existed. Once a future session adds
 * Api\Directives\DirectiveController / Api\Reports\ReportController, the
 * TC-NFR-SEC-006-B-shaped real-endpoint 404 assertion (see the inquiries
 * test above) should be added here too — this is a documented gap, not a
 * silent omission.
 */
it('never returns another ministry\'s directives from a ministry-scoped query (TC-NFR-SEC-006-A, directives)', function () {
    $ownMinistry = Ministry::factory()->create();
    $otherMinistry = Ministry::factory()->create();

    $attacheRole = Role::factory()->create(['name' => 'Ministry Attache', 'layer' => '2', 'scope' => 'mission']);
    $attache = User::factory()->create(['role_id' => $attacheRole->id, 'ministry_id' => $ownMinistry->id]);

    Directive::factory()->create(['ministry_id' => $ownMinistry->id]);
    $foreignDirective = Directive::factory()->create(['ministry_id' => $otherMinistry->id]);

    $this->actingAs($attache);

    $directives = Directive::all();

    expect($directives)->toHaveCount(1);
    expect($directives->pluck('id'))->not->toContain($foreignDirective->id);
});

it('never returns another ministry\'s periodic reports from a ministry-scoped query (TC-NFR-SEC-006-A, periodic_reports)', function () {
    $ownMinistry = Ministry::factory()->create();
    $otherMinistry = Ministry::factory()->create();

    $attacheRole = Role::factory()->create(['name' => 'Ministry Attache', 'layer' => '2', 'scope' => 'mission']);
    $attache = User::factory()->create(['role_id' => $attacheRole->id, 'ministry_id' => $ownMinistry->id]);

    PeriodicReport::factory()->create(['ministry_id' => $ownMinistry->id]);
    $foreignReport = PeriodicReport::factory()->create(['ministry_id' => $otherMinistry->id]);

    $this->actingAs($attache);

    $reports = PeriodicReport::all();

    expect($reports)->toHaveCount(1);
    expect($reports->pluck('id'))->not->toContain($foreignReport->id);
});

it('never surfaces another ministry\'s alerts or inquiries through full-text search (TC-NFR-SEC-006-C)', function () {
    $ownMinistry = Ministry::factory()->create();
    $otherMinistry = Ministry::factory()->create();
    $ownMission = Mission::factory()->create();
    $otherMission = Mission::factory()->create();
    $attache = ministryIsolationAttache($ownMinistry, $ownMission);

    $needle = 'IsolationFixtureNeedleZQX987';

    Alert::factory()->create([
        'ministry_id' => $otherMinistry->id,
        'mission_id' => $otherMission->id,
        'product_description' => $needle,
    ]);
    Inquiry::factory()->create([
        'ministry_id' => $otherMinistry->id,
        'mission_id' => $otherMission->id,
        'description' => $needle,
    ]);

    $response = $this->actingAs($attache)->getJson('/api/v1/search?q='.$needle);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

// --- ADR-006 / BR-025: Ministry Administrator isolation and operational fence ---

function ministryIsolationAdministrator(Ministry $ministry): User
{
    $role = Role::query()->firstOrCreate(['name' => 'Ministry Administrator'], ['layer' => '1', 'scope' => 'ministry']);

    return User::factory()->create(['role_id' => $role->id, 'ministry_id' => $ministry->id]);
}

it('denies a Ministry Administrator every operational endpoint, reads included (TC-NFR-SEC-006-MA-A)', function (string $method, string $uri) {
    $administrator = ministryIsolationAdministrator(Ministry::factory()->create());

    // 403 from the fence; /mission-activity already answers 404 to any account
    // without a mission assignment before authorisation runs — still a denial.
    expect($this->actingAs($administrator)->json($method, $uri)->status())->toBeIn([403, 404]);
})->with([
    'alerts list' => ['GET', '/api/v1/alerts'],
    'alert submit' => ['POST', '/api/v1/alerts'],
    'inquiries list' => ['GET', '/api/v1/inquiries'],
    'directives list' => ['GET', '/api/v1/directives'],
    'periodic reports list' => ['GET', '/api/v1/periodic-reports'],
    'kpi actuals' => ['GET', '/api/v1/kpi-actuals'],
    'kpi comparison' => ['GET', '/api/v1/kpi-comparison'],
    'kpi targets' => ['POST', '/api/v1/kpi-targets'],
    'search' => ['GET', '/api/v1/search?q=avocado'],
    'PS dashboard' => ['GET', '/api/v1/sdt/dashboard'],
    'report compliance' => ['GET', '/api/v1/sdt/reports/compliance'],
    'referral summary' => ['GET', '/api/v1/referrals/summary'],
    'mission activity' => ['GET', '/api/v1/mission-activity'],
]);

it('reaches its own department\'s administration endpoints (TC-NFR-SEC-006-MA-B)', function (string $uri) {
    $administrator = ministryIsolationAdministrator(Ministry::factory()->create());

    $this->actingAs($administrator)->getJson($uri)->assertOk();
})->with([
    '/api/v1/users',
    '/api/v1/report-templates',
    '/api/v1/kpi-definitions',
    '/api/v1/kpi-profiles',
    '/api/v1/sdt/config/alert-fields',
    '/api/v1/sdt/config/referral-organisations',
    '/api/v1/master-data',
    '/api/v1/audit-logs',
    '/api/v1/approval-requests',
    '/api/v1/ministries',
    '/api/v1/missions',
]);

it('is confined to its own department by the global scope, never bypassing it (TC-NFR-SEC-006-MA-C)', function () {
    $ownMinistry = Ministry::factory()->create();
    $otherMinistry = Ministry::factory()->create();
    $administrator = ministryIsolationAdministrator($ownMinistry);

    Alert::factory()->create(['ministry_id' => $ownMinistry->id]);
    Alert::factory()->create(['ministry_id' => $otherMinistry->id]);

    $this->actingAs($administrator);

    expect(Alert::all())->toHaveCount(1);
});

it('never sees or edits another department\'s configuration (TC-NFR-SEC-006-MA-D)', function () {
    $ownMinistry = Ministry::factory()->create();
    $otherMinistry = Ministry::factory()->create();
    $administrator = ministryIsolationAdministrator($ownMinistry);
    $ownKpi = KpiDefinition::factory()->create(['ministry_id' => $ownMinistry->id]);
    $foreignKpi = KpiDefinition::factory()->create(['ministry_id' => $otherMinistry->id]);

    $ids = collect($this->actingAs($administrator)->getJson('/api/v1/sdt/config/kpi-settings?ministry_id='.$otherMinistry->id)->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($ownKpi->id)->not->toContain($foreignKpi->id);
    $this->actingAs($administrator)->patchJson("/api/v1/sdt/config/kpi-settings/{$foreignKpi->id}", ['name' => 'Hijacked'])->assertNotFound();
    $this->actingAs($administrator)->patchJson("/api/v1/kpi-definitions/{$foreignKpi->id}", ['name' => 'Hijacked'])->assertNotFound();

    expect($foreignKpi->fresh()->name)->not->toBe('Hijacked');
});
