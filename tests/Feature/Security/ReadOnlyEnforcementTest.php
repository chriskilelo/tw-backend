<?php

use App\Models\Alert;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Directive;
use App\Models\Inquiry;
use App\Models\KpiDefinition;
use App\Models\KpiProfile;
use App\Models\MasterDataEntry;
use App\Models\Notification;
use App\Models\ReferralEntry;
use App\Models\ReferralOrganisation;
use App\Models\ReportDataRow;
use App\Models\ReportSection;
use App\Models\Role;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Services\PermissionCatalogueService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Directives\DirectiveWorld;
use Tests\Feature\Governance\GovernanceWorld;
use Tests\Feature\Reports\ReportWorld;
use Tests\TestCase;

/**
 * CLAUDE.md Section 4, Rule 2 / BR-020, FR-AUTH-017, DES-003: the four
 * mission-governance roles are structurally read-only. This must hold at
 * the permission catalogue AND at the policy/HTTP layer, not only in the
 * frontend.
 */
class TestAlertPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Alert $alert): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Alert $alert): bool
    {
        return true;
    }

    public function delete(User $user, Alert $alert): bool
    {
        return true;
    }
}

function registerReadOnlyEnforcementTestRoutes(): void
{
    Gate::policy(Alert::class, TestAlertPolicy::class);

    Route::middleware(['ministry.scope'])->group(function (): void {
        Route::post('/__test/alerts', function () {
            Gate::authorize('create', Alert::class);

            return response()->json(['data' => ['created' => true]], 201);
        });

        Route::patch('/__test/alerts/{id}', function (string $id) {
            Gate::authorize('update', Alert::findOrFail($id));

            return response()->json(['data' => ['updated' => true]]);
        });

        Route::delete('/__test/alerts/{id}', function (string $id) {
            Gate::authorize('delete', Alert::findOrFail($id));

            return response()->json(null, 204);
        });
    });
}

it('rejects assigning a write-type permission to Head of Mission at the catalogue level (TC-FR-AUTH-017)', function () {
    $role = Role::factory()->create(['name' => 'Head of Mission', 'layer' => '1', 'scope' => 'mission']);

    expect(fn () => app(PermissionCatalogueService::class)->assignPermission($role, 'alert.create'))
        ->toThrow(InvalidArgumentException::class);
});

it('allows assigning a read-type permission to Head of Mission', function () {
    $role = Role::factory()->create(['name' => 'Head of Mission', 'layer' => '1', 'scope' => 'mission']);

    $permission = app(PermissionCatalogueService::class)->assignPermission($role, 'alert.view');

    expect($permission->read_only)->toBeTrue();
});

it('seeds the catalogue without ever granting a write-type key to a read-only role', function () {
    Role::factory()->create(['name' => 'Head of Mission', 'layer' => '1', 'scope' => 'mission']);
    Role::factory()->create(['name' => 'Deputy Head of Mission', 'layer' => '1', 'scope' => 'mission']);
    Role::factory()->create(['name' => 'MFA HQ Officer', 'layer' => '1', 'scope' => 'platform']);
    Role::factory()->create(['name' => 'MFA Principal Secretary', 'layer' => '1', 'scope' => 'platform']);

    app(PermissionCatalogueService::class)->seed();

    foreach (BasePolicy::READ_ONLY_ROLES as $roleName) {
        $role = Role::where('name', $roleName)->first();

        $writeGrants = $role->permissions()
            ->whereIn('permission_key', PermissionCatalogueService::WRITE_TYPE_KEYS)
            ->count();

        expect($writeGrants)->toBe(0);
    }
});

it('returns 403 on a POST call for a Head of Mission user (TC-DES-003)', function () {
    registerReadOnlyEnforcementTestRoutes();

    $role = Role::factory()->create(['name' => 'Head of Mission', 'layer' => '1', 'scope' => 'mission']);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)
        ->postJson('/__test/alerts', [])
        ->assertForbidden();
});

it('returns 403 on a PATCH call for a Head of Mission user (TC-DES-003)', function () {
    registerReadOnlyEnforcementTestRoutes();

    $role = Role::factory()->create(['name' => 'Head of Mission', 'layer' => '1', 'scope' => 'mission']);
    $user = User::factory()->create(['role_id' => $role->id]);
    $alert = Alert::factory()->create();

    $this->actingAs($user)
        ->patchJson("/__test/alerts/{$alert->id}", [])
        ->assertForbidden();
});

it('returns 403 on a DELETE call for a Head of Mission user (TC-DES-003)', function () {
    registerReadOnlyEnforcementTestRoutes();

    $role = Role::factory()->create(['name' => 'Head of Mission', 'layer' => '1', 'scope' => 'mission']);
    $user = User::factory()->create(['role_id' => $role->id]);
    $alert = Alert::factory()->create();

    $this->actingAs($user)
        ->deleteJson("/__test/alerts/{$alert->id}")
        ->assertForbidden();
});

it('still allows a write-capable role (Ministry Attache) to pass the same policy check', function () {
    registerReadOnlyEnforcementTestRoutes();

    $role = Role::factory()->create(['name' => 'Ministry Attache', 'layer' => '2', 'scope' => 'mission']);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)
        ->postJson('/__test/alerts', [])
        ->assertCreated();
});

/**
 * The Directive engine (CLAUDE.md Section 11's DirectiveService) has no
 * Api\Directives\DirectiveController or routes yet — only its model, enum,
 * and notification job exist (no session has built it). This test-only
 * policy and route exist purely to prove BasePolicy::before() (the
 * structural mechanism every real engine policy inherits) also covers
 * Directive ahead of the real controller shipping, the same way
 * TestAlertPolicy above proved it for Alert before AlertController existed
 * (Session 5). The real Directive endpoints now exist and are asserted
 * below (TC-FR-AUTH-017-DIR); this placeholder is kept, not deleted, since
 * existing test coverage is never removed without approval.
 */
class TestDirectivePolicy extends BasePolicy
{
    public function create(User $user): bool
    {
        return true;
    }
}

function registerDirectiveReadOnlyTestRoute(): void
{
    Gate::policy(Directive::class, TestDirectivePolicy::class);

    Route::post('/__test/directives', function () {
        Gate::authorize('create', Directive::class);

        return response()->json(['data' => ['created' => true]], 201);
    });
}

/**
 * Exercises the real, production API-001 endpoints directly (not the
 * ad-hoc /__test/* routes above), per FR-AUTH-017/DES-003/NFR-SEC-005: a
 * read-only role must be rejected with 403 regardless of what a future
 * controller's own policy method would otherwise return, because
 * BasePolicy::before() denies every non-view ability before any concrete
 * policy method runs.
 */
function assertReadOnlyRoleForbiddenOnRealWriteEndpoints(TestCase $test, User $user): void
{
    registerDirectiveReadOnlyTestRoute();

    $inquiry = Inquiry::factory()->create();

    $test->actingAs($user)
        ->postJson('/api/v1/alerts', ['country' => 'Test Country', 'intelligence_type' => 'opportunities'])
        ->assertForbidden();

    $test->actingAs($user)
        ->postJson('/api/v1/inquiries', [
            'category' => 'General Market Question',
            'inquirer_name' => 'Test Inquirer',
            'date_received' => now()->toDateString(),
        ])
        ->assertForbidden();

    $test->actingAs($user)
        ->patchJson("/api/v1/inquiries/{$inquiry->id}/status", ['status' => 'received'])
        ->assertForbidden();

    $test->actingAs($user)
        ->postJson('/__test/directives', ['description' => 'Test'])
        ->assertForbidden();
}

it('returns 403 on every write attempt for Head of Mission (TC-FR-AUTH-017-HoM)', function () {
    $role = Role::factory()->create(['name' => 'Head of Mission', 'layer' => '1', 'scope' => 'mission']);
    $user = User::factory()->create(['role_id' => $role->id]);

    assertReadOnlyRoleForbiddenOnRealWriteEndpoints($this, $user);
});

it('returns 403 on every write attempt for Deputy Head of Mission (TC-FR-AUTH-017-DHoM)', function () {
    $role = Role::factory()->create(['name' => 'Deputy Head of Mission', 'layer' => '1', 'scope' => 'mission']);
    $user = User::factory()->create(['role_id' => $role->id]);

    assertReadOnlyRoleForbiddenOnRealWriteEndpoints($this, $user);
});

it('returns 403 on every write attempt for MFA HQ Officer (TC-FR-AUTH-017-MFA)', function () {
    $role = Role::factory()->create(['name' => 'MFA HQ Officer', 'layer' => '1', 'scope' => 'platform']);
    $user = User::factory()->create(['role_id' => $role->id]);

    assertReadOnlyRoleForbiddenOnRealWriteEndpoints($this, $user);
});

it('returns 403 on every write attempt for MFA Principal Secretary (TC-FR-AUTH-017-MFAPS)', function () {
    $role = Role::factory()->create(['name' => 'MFA Principal Secretary', 'layer' => '1', 'scope' => 'platform']);
    $user = User::factory()->create(['role_id' => $role->id]);

    assertReadOnlyRoleForbiddenOnRealWriteEndpoints($this, $user);
});

/**
 * The real Directive and Tasking Engine endpoints (DirectiveController).
 * The four read-only roles bypass ministry scope, so each request reaches
 * DirectivePolicy: every write is refused by BasePolicy::before(), and the
 * reads are refused too, because the module is limited to the directive
 * roles (otherwise the list would expose every department's directives).
 * Deliberately separate from assertReadOnlyRoleForbiddenOnRealWriteEndpoints(),
 * which swaps in TestDirectivePolicy.
 */
it('returns 403 on every real directive endpoint for the four read-only roles (TC-FR-AUTH-017-DIR)', function (string $roleName) {
    Queue::fake();

    $world = DirectiveWorld::create();
    $user = $world->member($roleName);
    $directive = $world->directive();
    $completed = $world->directive(['status' => 'completed']);

    $this->actingAs($user)->postJson('/api/v1/directives', $world->issueBody())->assertForbidden();
    $this->actingAs($user)->patchJson("/api/v1/directives/{$directive->id}", ['type_category' => 'X'])->assertForbidden();
    $this->actingAs($user)->patchJson("/api/v1/directives/{$directive->id}/status", ['status' => 'acknowledged'])->assertForbidden();
    $this->actingAs($user)->patchJson("/api/v1/directives/{$directive->id}/status", ['status' => 'cancelled', 'note' => 'x'])->assertForbidden();
    $this->actingAs($user)->patchJson("/api/v1/directives/{$completed->id}/status", ['status' => 'closed'])->assertForbidden();
    $this->actingAs($user)->postJson("/api/v1/directives/{$directive->id}/notes", ['content' => 'x'])->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/directives')->assertForbidden();
    $this->actingAs($user)->getJson("/api/v1/directives/{$directive->id}")->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/directives/summary')->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/directives/assignees')->assertForbidden();

    expect(Directive::query()->withoutGlobalScopes()->count())->toBe(2)
        ->and($directive->fresh()->status->value)->toBe('issued')
        ->and($directive->fresh()->type_category)->toBeNull()
        ->and($completed->fresh()->status->value)->toBe('completed');
})->with(['Head of Mission', 'Deputy Head of Mission', 'MFA HQ Officer', 'MFA Principal Secretary']);

/**
 * The same guarantee on the real Periodic Report Engine endpoints: the four
 * read-only roles never write a report (BasePolicy::before()). A Head or
 * Deputy Head of Mission may still READ their own mission's submitted
 * reports (FR-HOM-001 AC2); the MFA roles see metadata only (FR-MFA-001 AC2)
 * and are refused the reports themselves.
 */
it('returns 403 on every real report write endpoint for the four read-only roles (TC-FR-AUTH-017-RPT)', function (string $roleName, int $readStatus) {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-05 09:00:00'));

    $world = ReportWorld::create();
    $submitted = $world->submitted('Q4 2026');
    $draft = $world->draft('Q1 2026');
    $intro = $world->section($draft, ReportWorld::INTRODUCTION);
    $aie = $world->section($draft, ReportWorld::AIE);
    $user = $world->member($roleName);
    $base = "/api/v1/periodic-reports/{$draft->id}";

    $this->actingAs($user)->postJson('/api/v1/periodic-reports', ['reporting_period_label' => 'Q2 2026'])->assertForbidden();
    $this->actingAs($user)->patchJson("{$base}/sections/{$intro->id}", ['content' => 'x'])->assertForbidden();
    $this->actingAs($user)->patchJson("{$base}/sections/{$aie->id}", ['rows' => []])->assertForbidden();
    $this->actingAs($user)->postJson("{$base}/data-rows", ['section_id' => $aie->id, 'row_data' => ['Budget Code' => '1', 'Head Description' => 'x', 'Quarter Allocation' => 1]])->assertForbidden();
    $this->actingAs($user)->deleteJson("{$base}/data-rows/{$aie->dataRows->first()->id}")->assertForbidden();
    $this->actingAs($user)->postJson("{$base}/carry-forward")->assertForbidden();
    $this->actingAs($user)->postJson("{$base}/submit")->assertForbidden();
    $this->actingAs($user)->deleteJson($base)->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/periodic-reports/compliance')->assertForbidden();

    expect($this->actingAs($user)->getJson("/api/v1/periodic-reports/{$submitted->id}")->status())->toBe($readStatus)
        ->and($this->actingAs($user)->getJson('/api/v1/periodic-reports')->status())->toBe($readStatus);

    $this->actingAs($user)->getJson("/api/v1/periodic-reports/{$draft->id}")->assertForbidden();

    expect($draft->fresh()->status->value)->toBe('draft')
        ->and($intro->fresh()->content)->toBeNull()
        ->and($aie->dataRows()->count())->toBe(3);
})->with([
    'Head of Mission' => ['Head of Mission', 200],
    'Deputy Head of Mission' => ['Deputy Head of Mission', 200],
    'MFA HQ Officer' => ['MFA HQ Officer', 403],
    'MFA Principal Secretary' => ['MFA Principal Secretary', 403],
]);

/**
 * NFR-SEC-005 AC1 ("no configuration path SHALL exist to grant these four
 * roles create, edit, approve, reject, delete, or export permissions"),
 * checked structurally: every write route the API registers is tried by
 * each governance role, with every route parameter bound to a real record of
 * the role's own mission and department, and none may succeed. Only the
 * self-service routes every account has (signing out, preferences, profile
 * photo, its own notifications) and the unauthenticated ones are exempt. A
 * 5xx would mean a route failed open into an error rather than refusing, so
 * that fails the sweep too; and since every mutation writes an audit row
 * (ModelObserver), the role must leave none behind.
 */
it('lets no write route on the API succeed for a governance role (TC-NFR-SEC-005-SWEEP)', function (string $roleName) {
    Queue::fake();
    Mail::fake();
    Storage::fake('uploads');
    $this->travelTo(Carbon::parse(GovernanceWorld::TODAY));

    $world = GovernanceWorld::create();
    $user = $world->member($roleName);
    $bindings = governanceSweepBindings($world, $user);
    $exempt = [
        'api/v1/login', 'api/v1/logout', 'api/v1/password/forgot', 'api/v1/password/reset',
        'api/v1/me/avatar', 'api/v1/me/preferences',
        'api/v1/notifications/mark-all-read', 'api/v1/notifications/{notification}/read',
    ];

    $attempted = 0;
    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/') || in_array($route->uri(), $exempt, true)) {
            continue;
        }

        foreach (array_diff($route->methods(), ['GET', 'HEAD']) as $method) {
            $uri = preg_replace_callback('/\{(\w+)\??\}/', fn (array $match): string => $bindings[$match[1]], $route->uri());
            $status = $this->actingAs($user)->json($method, "/{$uri}", [])->status();

            expect($status)->toBeGreaterThanOrEqual(400, "{$roleName}: {$method} /{$uri} answered {$status}")
                ->toBeLessThan(500, "{$roleName}: {$method} /{$uri} answered {$status}");
            $attempted++;
        }
    }

    expect($attempted)->toBeGreaterThan(60)
        ->and(AuditLog::query()->where('user_id', $user->id)->count())->toBe(0);
})->with(['Head of Mission', 'Deputy Head of Mission', 'MFA HQ Officer', 'MFA Principal Secretary']);

/**
 * A real record for every route parameter the write routes use, all at the
 * Head of Mission's own mission (London) and in the Trade department.
 *
 * @return array<string, string>
 */
function governanceSweepBindings(GovernanceWorld $world, User $user): array
{
    $report = $world->report($world->london, $world->trade, 'Q1 2026', null);
    $section = ReportSection::factory()->create(['periodic_report_id' => $report->id]);
    $inquiry = $world->inquiry($world->london, $world->trade, '2026-10-07 09:00:00');

    return [
        'alert' => $world->alert($world->london, $world->trade, '2026-10-05 09:00:00')->id,
        'approvalRequest' => ApprovalRequest::factory()->create(['ministry_id' => $world->trade->id])->id,
        'directive' => $world->directive($world->london, $world->trade, '2026-10-06 09:00:00')->id,
        'inquiry' => $inquiry->id,
        'kpiDefinition' => KpiDefinition::factory()->create(['ministry_id' => $world->trade->id])->id,
        'kpiProfile' => KpiProfile::factory()->create(['ministry_id' => $world->trade->id])->id,
        'masterDataEntry' => MasterDataEntry::factory()->create(['ministry_id' => $world->trade->id])->id,
        'mission' => $world->london->id,
        'notification' => Notification::factory()->create(['recipient_user_id' => $user->id])->id,
        'periodicReport' => $report->id,
        'section' => $section->id,
        'dataRow' => ReportDataRow::factory()->create(['report_section_id' => $section->id])->id,
        'referralEntry' => ReferralEntry::factory()->create(['inquiry_id' => $inquiry->id])->id,
        'referralOrganisation' => ReferralOrganisation::factory()->create(['ministry_id' => $world->trade->id])->id,
        'user' => $world->londonTradeAttache->id,
    ];
}
