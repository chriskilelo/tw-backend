<?php

use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Ministry;
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

// --- FR-AUDIT-006: the Ministry Administrator's department audit view ---

it('shows a Ministry Administrator only its own department\'s administrative entries (TC-FR-AUDIT-006)', function () {
    $ownMinistry = Ministry::factory()->create();
    $otherMinistry = Ministry::factory()->create();
    $administratorRole = Role::query()->firstOrCreate(['name' => 'Ministry Administrator'], ['layer' => '1', 'scope' => 'ministry']);
    $officerRole = Role::query()->firstOrCreate(['name' => 'Ministry HQ Officer'], ['layer' => '2', 'scope' => 'ministry']);
    $administrator = User::factory()->create(['role_id' => $administratorRole->id, 'ministry_id' => $ownMinistry->id]);

    $ownOfficer = User::factory()->create(['role_id' => $officerRole->id, 'ministry_id' => $ownMinistry->id]);
    $foreignOfficer = User::factory()->create(['role_id' => $officerRole->id, 'ministry_id' => $otherMinistry->id]);
    $ownAlert = Alert::factory()->create(['ministry_id' => $ownMinistry->id]);

    $response = $this->actingAs($administrator)->getJson('/api/v1/audit-logs?per_page=100')->assertOk();
    $entityIds = collect($response->json('data'))->pluck('affected_entity_id');

    // Operational rows carry record content in `changes`, so even the
    // department's own alert history is excluded (BR-025).
    expect($entityIds)->toContain($ownOfficer->id)
        ->not->toContain($foreignOfficer->id)
        ->not->toContain($ownAlert->id);
    expect(collect($response->json('data'))->pluck('ministry_id')->unique()->all())->toBe([$ownMinistry->id]);
});

it('records the department on every new audit entry (TC-FR-AUDIT-006-B)', function () {
    $ministry = Ministry::factory()->create();
    $alert = Alert::factory()->create(['ministry_id' => $ministry->id]);

    expect(AuditLog::where('affected_entity_id', $alert->id)->where('action', 'alert.created')->first()->ministry_id)->toBe($ministry->id);
});
