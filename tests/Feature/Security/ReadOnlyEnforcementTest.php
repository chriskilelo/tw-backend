<?php

use App\Models\Alert;
use App\Models\Directive;
use App\Models\Inquiry;
use App\Models\Role;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Services\PermissionCatalogueService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
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
 * (Session 5). Replace this with a real POST /api/v1/directives assertion
 * once that engine is built; do not delete this coverage silently.
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
