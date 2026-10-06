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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Directives\DirectiveWorld;
use Tests\Feature\Reports\ReportWorld;

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
 * silent omission. The Directive engine's real endpoints now exist and are
 * asserted further down (TC-NFR-SEC-006-*, directives, real endpoints).
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

it('never counts another ministry\'s alerts or inquiries on the leadership dashboard (TC-NFR-SEC-006-D)', function () {
    $ownMinistry = Ministry::factory()->create();
    $otherMinistry = Ministry::factory()->create();
    $role = Role::query()->firstOrCreate(['name' => 'Ministry HQ Director'], ['layer' => '2/3', 'scope' => 'ministry']);
    $director = User::factory()->create(['role_id' => $role->id, 'ministry_id' => $ownMinistry->id]);

    Alert::factory()->count(2)->create(['ministry_id' => $ownMinistry->id]);
    Alert::factory()->count(5)->create(['ministry_id' => $otherMinistry->id]);
    Inquiry::factory()->count(4)->create(['ministry_id' => $otherMinistry->id, 'status' => 'in_progress']);

    $response = $this->actingAs($director)->getJson('/api/v1/dashboard');

    $response->assertOk();
    expect($response->json('data.alerts.this_quarter'))->toBe(2)
        ->and($response->json('data.alerts.unacknowledged'))->toBe(2)
        ->and($response->json('data.inquiries.open'))->toBe(0);
});

// --- ADR-006 / BR-025: Ministry Administrator isolation and operational fence ---

function ministryIsolationAdministrator(Ministry $ministry): User
{
    $role = Role::query()->firstOrCreate(['name' => 'Ministry Administrator'], ['layer' => '1', 'scope' => 'ministry']);

    return User::factory()->create(['role_id' => $role->id, 'ministry_id' => $ministry->id]);
}

it('denies a Ministry Administrator every operational endpoint, reads included (TC-NFR-SEC-006-MA-A)', function (string $method, string $uri) {
    $administrator = ministryIsolationAdministrator(Ministry::factory()->create());

    // 403 from the fence or the policy. /mission-activity used to answer 404 to
    // an account without a mission; it now authorises first and answers 403.
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
    'operational dashboard' => ['GET', '/api/v1/dashboard'],
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
    '/api/v1/admin/dashboard',
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

// --- Directive and Tasking Engine, real endpoints ---

it('never lists another ministry\'s directives through the real directives endpoint (TC-NFR-SEC-006-A, directives, real endpoint)', function () {
    $own = DirectiveWorld::create();
    $foreign = DirectiveWorld::create();
    $ownDirective = $own->directive();
    $foreign->directive();

    foreach ([$own->attache, $own->officer, $own->ps, $own->director] as $actor) {
        $ids = collect($this->actingAs($actor)->getJson('/api/v1/directives?per_page=100')->assertOk()->json('data'))->pluck('id')->all();

        expect($ids)->toBe([$ownDirective->id]);
    }
});

it('returns 404 on direct UUID access to another ministry\'s directive through every real endpoint (TC-NFR-SEC-006-B, directives)', function () {
    Queue::fake();

    $own = DirectiveWorld::create();
    $foreign = DirectiveWorld::create()->directive();

    $this->actingAs($own->ps)->getJson("/api/v1/directives/{$foreign->id}")->assertNotFound();
    $this->actingAs($own->officer)->patchJson("/api/v1/directives/{$foreign->id}", ['type_category' => 'X'])->assertNotFound();
    $this->actingAs($own->attache)->patchJson("/api/v1/directives/{$foreign->id}/status", ['status' => 'acknowledged'])->assertNotFound();
    $this->actingAs($own->attache)->postJson("/api/v1/directives/{$foreign->id}/notes", ['content' => 'x'])->assertNotFound();

    expect($foreign->fresh()->status->value)->toBe('issued')
        ->and($foreign->notes()->count())->toBe(0);
});

it('never counts, offers or targets another ministry\'s directives, missions or attaches (TC-NFR-SEC-006-D, directives)', function () {
    Queue::fake();

    $own = DirectiveWorld::create();
    $foreign = DirectiveWorld::create();
    $own->directive();
    $foreign->directive();
    $foreign->directive();

    $summary = $this->actingAs($own->director)->getJson('/api/v1/directives/summary')->assertOk()->json('data');
    expect($summary['total'])->toBe(1)
        ->and(collect($summary['filter_options']['issuers'])->pluck('id')->all())->toBe([$own->officer->id]);

    $missionIds = collect($this->actingAs($own->officer)->getJson('/api/v1/directives/assignees')->assertOk()->json('data'))->pluck('id');
    expect($missionIds->all())->toEqualCanonicalizing([$own->mission->id, $own->otherMission->id]);

    $this->actingAs($own->officer)
        ->postJson('/api/v1/directives', $own->issueBody(['mission_id' => $foreign->mission->id, 'target_user_id' => $foreign->attache->id]))
        ->assertUnprocessable();

    expect(Directive::query()->withoutGlobalScopes()->count())->toBe(3);
});

/**
 * Periodic reports through the real endpoints (replacing the Eloquent-level
 * check above as the primary guarantee, per the Session 16 note): another
 * department's report is invisible in lists, the compliance dashboard and
 * search, and a 404 by id.
 */
it('never exposes another ministry\'s periodic reports through the real endpoints (TC-NFR-SEC-006-A/B, periodic_reports, real endpoint)', function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-05 09:00:00'));

    $world = ReportWorld::create();
    $own = $world->submitted('Q4 2026');
    $foreign = $world->submitted('Q4 2026', $world->foreignAttache);

    foreach ([$world->officer, $world->director, $world->ps] as $user) {
        $ids = collect($this->actingAs($user)->getJson('/api/v1/periodic-reports')->assertOk()->json('data'))->pluck('id')->all();

        expect($ids)->toBe([$own->id]);

        $this->actingAs($user)->getJson("/api/v1/periodic-reports/{$foreign->id}")->assertNotFound();
    }

    $missions = collect($this->actingAs($world->ps)->getJson('/api/v1/periodic-reports/compliance?period_label=Q4%202026')->json('data.missions'))
        ->pluck('mission_name')->all();

    expect($missions)->not->toContain('Cairo');
});
