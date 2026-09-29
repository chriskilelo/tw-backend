<?php

use App\Enums\UserStatus;
use App\Models\Alert;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * GET /api/v1/admin/dashboard (App\Services\AdministrationDashboardService,
 * ADR-006): account health, seat rules and configuration for the System
 * Administrator (every department) and the Ministry Administrator (its own
 * department only, and nothing operational — BR-025).
 */
function adminDashboardUser(string $roleName, ?Ministry $ministry, array $attributes = []): User
{
    $role = Role::query()->firstOrCreate(['name' => $roleName], ['layer' => '1', 'scope' => $ministry ? 'ministry' : 'platform']);

    return User::factory()->create(['role_id' => $role->id, 'ministry_id' => $ministry?->id, ...$attributes]);
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-29 10:00:00'));
});

it('gives a System Administrator the platform view, with storage, queue and seat checks (TC-FR-AUTH-024)', function () {
    $administrator = adminDashboardUser('System Administrator', null, ['last_login_at' => now()]);
    Ministry::factory()->count(2)->create();
    Alert::factory()->create();

    $response = $this->actingAs($administrator)->getJson('/api/v1/admin/dashboard');

    $response->assertOk();
    $checks = collect($response->json('data.health_checks'))->keyBy('key');

    expect($response->json('data.scope.type'))->toBe('platform')
        ->and($response->json('data.platform.system_administrators'))->toBe(1)
        ->and($response->json('data.platform.records.alerts'))->toBe(1)
        ->and($response->json('data.platform.storage.capacity_bytes'))->toBe(2_000_000_000_000)
        ->and($checks['system_administrators']['status'])->toBe('warn')
        ->and($checks['failed_jobs']['status'])->toBe('pass')
        ->and($response->json('data.sign_in_activity'))->toHaveCount(12);
});

it('pins a Ministry Administrator to its own department and shows nothing operational (TC-BR-025)', function () {
    $ownDepartment = Ministry::factory()->create();
    $otherDepartment = Ministry::factory()->create();
    $administrator = adminDashboardUser('Ministry Administrator', $ownDepartment);
    adminDashboardUser('Ministry Attache', $ownDepartment);
    adminDashboardUser('Ministry Attache', $otherDepartment);
    adminDashboardUser('Ministry Attache', $otherDepartment);

    $response = $this->actingAs($administrator)->getJson('/api/v1/admin/dashboard?ministry='.$otherDepartment->id);

    $response->assertOk();
    expect($response->json('data.scope.type'))->toBe('department')
        ->and($response->json('data.scope.ministry.id'))->toBe($ownDepartment->id)
        ->and($response->json('data.accounts.total'))->toBe(2)
        ->and($response->json('data.departments'))->toHaveCount(1)
        ->and($response->json('data.platform'))->toBeNull()
        ->and(collect($response->json('data.health_checks'))->pluck('key'))->not->toContain('system_administrators', 'failed_jobs');
});

it('lets a System Administrator narrow the view to one department', function () {
    $administrator = adminDashboardUser('System Administrator', null);
    $department = Ministry::factory()->create();
    adminDashboardUser('Ministry Attache', $department);
    adminDashboardUser('Ministry Attache', Ministry::factory()->create());

    $response = $this->actingAs($administrator)->getJson('/api/v1/admin/dashboard?ministry='.$department->id);

    expect($response->json('data.scope.type'))->toBe('department')
        ->and($response->json('data.accounts.total'))->toBe(1)
        ->and($response->json('data.platform'))->not->toBeNull();

    $this->actingAs($administrator)->getJson('/api/v1/admin/dashboard?ministry=not-a-uuid')->assertStatus(422);
});

it('measures sign-in recency, dormant accounts, stale invitations and accounts close to locking', function () {
    $department = Ministry::factory()->create();
    $administrator = adminDashboardUser('Ministry Administrator', $department, ['last_login_at' => now()->subDay()]);

    adminDashboardUser('Ministry Attache', $department, ['last_login_at' => now()->subDays(12)]);
    adminDashboardUser('Ministry Attache', $department, ['last_login_at' => now()->subDays(45), 'failed_login_attempts' => 3]);
    adminDashboardUser('Ministry Attache', $department, ['last_login_at' => null, 'created_at' => now()->subDays(60)]);
    adminDashboardUser('Ministry Attache', $department, ['status' => UserStatus::ActivationPending, 'created_at' => now()->subDays(4)]);
    adminDashboardUser('Ministry Attache', $department, ['status' => UserStatus::ActivationPending, 'created_at' => now()->subHours(5)]);
    adminDashboardUser('Ministry Attache', $department, ['status' => UserStatus::Locked]);

    $response = $this->actingAs($administrator)->getJson('/api/v1/admin/dashboard');
    $recency = collect($response->json('data.accounts.sign_in_recency'))->pluck('count', 'key');
    $checks = collect($response->json('data.health_checks'))->keyBy('key');

    expect($recency->all())->toBe(['last_7_days' => 1, 'last_30_days' => 1, 'last_90_days' => 1, 'older' => 0, 'never' => 1])
        ->and($response->json('data.accounts.dormant'))->toBe(2)
        ->and($response->json('data.accounts.never_signed_in'))->toBe(1)
        ->and($response->json('data.accounts.stale_invitations'))->toBe(1)
        ->and($response->json('data.accounts.close_to_locking'))->toBe(1)
        ->and($response->json('data.accounts.by_status.locked'))->toBe(1)
        ->and($checks['dormant_accounts'])->toMatchArray(['status' => 'fail', 'value' => 50])
        ->and($checks['locked_accounts']['status'])->toBe('warn')
        ->and($checks['stale_invitations']['value'])->toBe(1);
});

it('fails the seat check when a department holds more Ministry Administrators than BR-028 allows', function () {
    $administrator = adminDashboardUser('System Administrator', null);
    $department = Ministry::factory()->create();
    foreach (range(1, 4) as $seat) {
        adminDashboardUser('Ministry Administrator', $department);
    }

    $response = $this->actingAs($administrator)->getJson('/api/v1/admin/dashboard?ministry='.$department->id);
    $check = collect($response->json('data.health_checks'))->firstWhere('key', 'ministry_administrators');

    expect($response->json('data.departments.0.ministry_administrators'))->toBe(['filled' => 4, 'limit' => 3])
        ->and($check)->toMatchArray(['status' => 'fail', 'value' => 1, 'detail' => ['over_limit']]);
});

it('reports the PS seat, vacant attache posts, configuration gaps and pending approvals', function () {
    $administrator = adminDashboardUser('System Administrator', null);
    $department = Ministry::factory()->create();
    $ps = adminDashboardUser('Ministry PS', $department);
    MissionMinistryLink::factory()->create(['ministry_id' => $department->id, 'mission_id' => Mission::factory()->create()->id, 'active_attache_user_id' => null]);
    ApprovalRequest::factory()->create(['ministry_id' => $department->id, 'created_at' => now()->subDays(9)]);

    $response = $this->actingAs($administrator)->getJson('/api/v1/admin/dashboard?ministry='.$department->id);
    $checks = collect($response->json('data.health_checks'))->keyBy('key');

    expect($response->json('data.departments.0.principal_secretary.id'))->toBe($ps->id)
        ->and($response->json('data.departments.0.attache_posts'))->toBe(['total' => 1, 'vacant' => 1])
        ->and($response->json('data.approvals.pending'))->toBe(1)
        ->and($response->json('data.approvals.overdue'))->toBe(1)
        ->and($checks['principal_secretaries']['status'])->toBe('pass')
        ->and($checks['vacant_attache_posts']['value'])->toBe(1)
        ->and($checks['overdue_approvals']['status'])->toBe('warn')
        ->and($checks['configuration_gaps']['detail'])->toContain('directive_types', 'report_template_sections');
});

it('lists what administrators changed, leaving out sign-in bookkeeping and self-updates', function () {
    $department = Ministry::factory()->create();
    $administrator = adminDashboardUser('Ministry Administrator', $department);

    AuditLog::factory()->create(['user_id' => $administrator->id, 'ministry_id' => $department->id, 'action' => 'user.login.succeeded', 'affected_entity_type' => User::class]);
    AuditLog::factory()->create(['user_id' => null, 'ministry_id' => $department->id, 'action' => 'user.updated', 'affected_entity_type' => User::class]);
    AuditLog::factory()->create(['user_id' => $administrator->id, 'ministry_id' => $department->id, 'action' => 'user.updated', 'affected_entity_type' => User::class, 'affected_entity_id' => $administrator->id]);
    AuditLog::factory()->create(['user_id' => $administrator->id, 'ministry_id' => $department->id, 'action' => 'user.deactivated', 'affected_entity_type' => User::class]);
    AuditLog::factory()->create(['user_id' => $administrator->id, 'ministry_id' => $department->id, 'action' => 'alert.created', 'affected_entity_type' => Alert::class]);

    $response = $this->actingAs($administrator)->getJson('/api/v1/admin/dashboard');

    expect(collect($response->json('data.recent_activity'))->pluck('action')->all())->toBe(['user.deactivated']);
});

it('is closed to every role but the two administrator tiers', function (string $roleName) {
    $user = adminDashboardUser($roleName, Ministry::factory()->create());

    $this->actingAs($user)->getJson('/api/v1/admin/dashboard')->assertForbidden();
})->with(['Ministry PS', 'Ministry Attache', 'Head of Mission', 'HRM&D Officer']);
