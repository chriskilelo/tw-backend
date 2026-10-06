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
    expect($log->changes['before'])->toBeNull();
    expect($log->changes['after']['country'])->toBe($alert->country);
});

it('records the full record on both sides of an update, not only the changed fields (TC-FR-AUDIT-002)', function () {
    $alert = Alert::factory()->create(['sector' => 'Agriculture']);
    $originalCountry = $alert->country;

    $alert->update(['sector' => 'Manufacturing']);

    $log = AuditLog::where('affected_entity_type', Alert::class)
        ->where('affected_entity_id', $alert->id)
        ->where('action', 'alert.updated')
        ->latest('created_at')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->changes['before']['sector'])->toBe('Agriculture');
    expect($log->changes['after']['sector'])->toBe('Manufacturing');
    // Unchanged fields are captured too (identically on both sides), so the
    // audit trail can render a full before/after record, not just a diff.
    expect($log->changes['before']['country'])->toBe($originalCountry);
    expect($log->changes['after']['country'])->toBe($originalCountry);
});

it('records the full prior state as before when a record is deleted (TC-FR-AUDIT-002-B)', function () {
    $alert = Alert::factory()->create();
    $alertId = $alert->id;

    $alert->delete();

    $log = AuditLog::where('affected_entity_type', Alert::class)
        ->where('affected_entity_id', $alertId)
        ->where('action', 'alert.deleted')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->changes['after'])->toBeNull();
    expect($log->changes['before']['id'])->toBe($alertId);
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

it('shows the actor\'s name and email even after the actor is deactivated (BR-002)', function () {
    $admin = systemAdministratorAuditUser();
    $officerRole = Role::query()->firstOrCreate(['name' => 'Ministry HQ Officer'], ['layer' => '2', 'scope' => 'ministry']);
    $officer = User::factory()->create(['role_id' => $officerRole->id, 'full_name' => 'Jane Officer', 'email' => 'jane@sdt.go.ke']);

    $alert = Alert::factory()->create();
    AuditLog::factory()->create([
        'user_id' => $officer->id,
        'action' => 'alert.created',
        'affected_entity_type' => Alert::class,
        'affected_entity_id' => $alert->id,
    ]);

    $officer->delete();

    $response = $this->actingAs($admin)->getJson('/api/v1/audit-logs')->assertOk();
    $entry = collect($response->json('data'))->firstWhere('user_id', $officer->id);

    expect($entry['user_full_name'])->toBe('Jane Officer');
    expect($entry['user_email'])->toBe('jane@sdt.go.ke');
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
