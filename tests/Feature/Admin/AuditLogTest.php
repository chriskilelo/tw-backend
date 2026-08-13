<?php

use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;

function systemAdministratorAuditUser(): User
{
    $role = Role::factory()->create(['name' => 'System Administrator', 'layer' => '1', 'scope' => 'platform']);

    return User::factory()->create(['role_id' => $role->id]);
}

it('generates an audit_logs entry when an alert is created (TC-FR-AUDIT-001)', function () {
    $alert = Alert::factory()->create();

    $log = AuditLog::where('affected_entity_type', Alert::class)
        ->where('affected_entity_id', $alert->id)
        ->where('action', 'alert.created')
        ->first();

    expect($log)->not->toBeNull();
});

it('throws when an update is attempted on a persisted audit_logs row (TC-FR-AUDIT-003)', function () {
    $log = AuditLog::factory()->create(['action' => 'alert.created']);

    expect(fn () => $log->fresh()->forceFill(['action' => 'alert.tampered'])->save())
        ->toThrow(RuntimeException::class);
});

it('rejects a non-System-Administrator from listing audit logs', function () {
    $role = Role::factory()->create(['name' => 'Ministry Attache', 'layer' => '2', 'scope' => 'mission']);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)->getJson('/api/v1/audit-logs')->assertForbidden();
});

it('returns audit log entries filterable by date range (TC-FR-AUDIT-005)', function () {
    $admin = systemAdministratorAuditUser();

    $inRange = AuditLog::factory()->create(['action' => 'alert.created', 'created_at' => '2026-06-15 10:00:00']);
    $outOfRange = AuditLog::factory()->create(['action' => 'alert.created', 'created_at' => '2026-01-01 10:00:00']);

    $response = $this->actingAs($admin)->getJson('/api/v1/audit-logs?from=2026-06-01&to=2026-06-30');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($inRange->id);
    expect($ids)->not->toContain($outOfRange->id);
});
