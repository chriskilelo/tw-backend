<?php

use App\Enums\AlertStatus;
use App\Enums\DirectiveStatus;
use App\Enums\InquiryStatus;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Directive;
use App\Models\Inquiry;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\Role;
use App\Models\User;

/**
 * CLAUDE.md Section 11, 14 / FR-SDT-001, FR-SDT-004 to 006, FR-SDT-012,
 * FR-SDT-013, FR-SDT-019, FR-SDT-020, FR-SDT-023. Session 14.
 */
function sdtRole(string $name, string $layer = '2/3', string $scope = 'ministry'): Role
{
    return Role::query()->firstOrCreate(['name' => $name], ['layer' => $layer, 'scope' => $scope]);
}

function ministryPs(Ministry $ministry): User
{
    return User::factory()->create([
        'role_id' => sdtRole('Ministry PS')->id,
        'ministry_id' => $ministry->id,
        'mission_id' => null,
    ]);
}

function ministryHqOfficer(Ministry $ministry): User
{
    return User::factory()->create([
        'role_id' => sdtRole('Ministry HQ Officer')->id,
        'ministry_id' => $ministry->id,
        'mission_id' => null,
    ]);
}

function sdtSystemAdministrator(): User
{
    return User::factory()->create([
        'role_id' => sdtRole('System Administrator', '1', 'platform')->id,
        'ministry_id' => null,
        'mission_id' => null,
    ]);
}

// App\Services\SdtService::activateActingPs() looks up the 'Acting PS'
// role by name; every test in this file may reach the activation endpoint,
// so it must always exist, mirroring how each *Role() helper above seeds
// the roles it directly assigns.
beforeEach(function () {
    sdtRole('Acting PS');
});

// --- FR-SDT-001: PS Dashboard ------------------------------------------

it('lets a Ministry PS access the dashboard (TC-FR-SDT-001)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $ps = ministryPs($ministry);

    Alert::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id, 'status' => AlertStatus::New->value]);
    Alert::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id, 'status' => AlertStatus::Acknowledged->value]);
    Inquiry::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id, 'status' => InquiryStatus::InProgress->value]);
    Inquiry::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id, 'status' => InquiryStatus::Closed->value]);
    Directive::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id]);

    $response = $this->actingAs($ps)->getJson('/api/v1/sdt/dashboard');

    $response->assertOk();
    expect($response->json('data.unacknowledged_alerts_count'))->toBe(1)
        ->and($response->json('data.pending_inquiries_count'))->toBe(1)
        ->and($response->json('data.directive_summary_this_week.total'))->toBe(1);
});

it('rejects a Ministry HQ Officer from the PS dashboard (TC-FR-SDT-001)', function () {
    $ministry = Ministry::factory()->create();
    $officer = ministryHqOfficer($ministry);

    $this->actingAs($officer)
        ->getJson('/api/v1/sdt/dashboard')
        ->assertForbidden();
});

it('only counts the requesting PS ministry\'s alerts on the dashboard', function () {
    $ministryA = Ministry::factory()->create();
    $ministryB = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $ps = ministryPs($ministryA);

    Alert::factory()->create(['ministry_id' => $ministryA->id, 'mission_id' => $mission->id, 'status' => AlertStatus::New->value]);
    Alert::factory()->create(['ministry_id' => $ministryB->id, 'mission_id' => $mission->id, 'status' => AlertStatus::New->value]);

    $response = $this->actingAs($ps)->getJson('/api/v1/sdt/dashboard');

    $response->assertOk()->assertJsonPath('data.unacknowledged_alerts_count', 1);
});

// --- FR-SDT-004 to 006: Acting PS ---------------------------------------

it('lets a Ministry PS activate an Acting PS and creates an audit_log entry (TC-FR-SDT-004)', function () {
    $ministry = Ministry::factory()->create();
    $ps = ministryPs($ministry);
    $officer = ministryHqOfficer($ministry);
    $originalRoleId = $officer->role_id;

    $response = $this->actingAs($ps)->postJson('/api/v1/sdt/acting-ps/activate', [
        'user_id' => $officer->id,
    ]);

    $response->assertOk();

    $officer->refresh();
    $ministry->refresh();

    expect($officer->role->name)->toBe('Acting PS')
        ->and($officer->acting_ps_original_role_id)->toBe($originalRoleId)
        ->and($ministry->acting_ps_active)->toBeTrue()
        ->and($ministry->acting_ps_user_id)->toBe($officer->id);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $ps->id,
        'action' => 'acting_ps.activated',
        'affected_entity_type' => 'ministry',
        'affected_entity_id' => $ministry->id,
    ]);

    $log = AuditLog::query()->where('action', 'acting_ps.activated')->first();
    expect($log->changes)->toMatchArray(['assumed_user_id' => $officer->id]);
});

it('rejects a second Acting PS activation while one is already active (BR-023)', function () {
    $ministry = Ministry::factory()->create();
    $ps = ministryPs($ministry);
    $officerA = ministryHqOfficer($ministry);
    $officerB = ministryHqOfficer($ministry);

    $this->actingAs($ps)->postJson('/api/v1/sdt/acting-ps/activate', ['user_id' => $officerA->id])->assertOk();

    $this->actingAs($ps)
        ->postJson('/api/v1/sdt/acting-ps/activate', ['user_id' => $officerB->id])
        ->assertStatus(422);
});

it('lets the activating PS deactivate the Acting PS and reverts their role (FR-SDT-006)', function () {
    $ministry = Ministry::factory()->create();
    $ps = ministryPs($ministry);
    $officer = ministryHqOfficer($ministry);
    $originalRoleId = $officer->role_id;

    $this->actingAs($ps)->postJson('/api/v1/sdt/acting-ps/activate', ['user_id' => $officer->id])->assertOk();

    $response = $this->actingAs($ps)->postJson('/api/v1/sdt/acting-ps/deactivate');

    $response->assertOk();

    $officer->refresh();
    $ministry->refresh();

    expect($officer->role_id)->toBe($originalRoleId)
        ->and($officer->acting_ps_original_role_id)->toBeNull()
        ->and($ministry->acting_ps_active)->toBeFalse()
        ->and($ministry->acting_ps_user_id)->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'acting_ps.deactivated',
        'affected_entity_type' => 'ministry',
        'affected_entity_id' => $ministry->id,
    ]);
});

it('rejects a Ministry HQ Officer from activating an Acting PS', function () {
    $ministry = Ministry::factory()->create();
    $officer = ministryHqOfficer($ministry);
    $otherOfficer = ministryHqOfficer($ministry);

    $this->actingAs($officer)
        ->postJson('/api/v1/sdt/acting-ps/activate', ['user_id' => $otherOfficer->id])
        ->assertForbidden();
});

it('lets a System Administrator activate an Acting PS in any ministry', function () {
    $ministry = Ministry::factory()->create();
    $admin = sdtSystemAdministrator();
    $officer = ministryHqOfficer($ministry);

    $this->actingAs($admin)
        ->postJson('/api/v1/sdt/acting-ps/activate', ['user_id' => $officer->id])
        ->assertOk();
});

it('grants the newly activated Acting PS access to the PS dashboard', function () {
    $ministry = Ministry::factory()->create();
    $ps = ministryPs($ministry);
    $officer = ministryHqOfficer($ministry);

    $this->actingAs($ps)->postJson('/api/v1/sdt/acting-ps/activate', ['user_id' => $officer->id])->assertOk();

    $officer->refresh();

    $this->actingAs($officer)->getJson('/api/v1/sdt/dashboard')->assertOk();
});

// --- FR-SDT-012, FR-SDT-013: HQ Workspace -------------------------------

it('lets a Ministry HQ Officer see only their own assigned items in the workspace (TC-FR-SDT-012)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $officerA = ministryHqOfficer($ministry);
    $officerB = ministryHqOfficer($ministry);

    $ownAlert = Alert::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'assigned_to_user_id' => $officerA->id,
    ]);
    Alert::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'assigned_to_user_id' => $officerB->id,
    ]);

    $ownDirective = Directive::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'issued_by_user_id' => $officerA->id,
        'status' => DirectiveStatus::Issued->value,
    ]);
    Directive::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'issued_by_user_id' => $officerB->id,
        'status' => DirectiveStatus::Issued->value,
    ]);

    $response = $this->actingAs($officerA)->getJson('/api/v1/sdt/hq-workspace');

    $response->assertOk();

    $alertIds = collect($response->json('data.assigned_alerts'))->pluck('id');
    $directiveIds = collect($response->json('data.pending_directives'))->pluck('id');

    expect($alertIds)->toEqual(collect([$ownAlert->id]))
        ->and($directiveIds)->toEqual(collect([$ownDirective->id]));
});

it('rejects a Ministry PS from the HQ Officer workspace', function () {
    $ministry = Ministry::factory()->create();
    $ps = ministryPs($ministry);

    $this->actingAs($ps)
        ->getJson('/api/v1/sdt/hq-workspace')
        ->assertForbidden();
});

it('filters the HQ workspace by mission_id', function () {
    $ministry = Ministry::factory()->create();
    $missionA = Mission::factory()->create();
    $missionB = Mission::factory()->create();
    $officer = ministryHqOfficer($ministry);

    $alertA = Alert::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $missionA->id,
        'assigned_to_user_id' => $officer->id,
    ]);
    Alert::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $missionB->id,
        'assigned_to_user_id' => $officer->id,
    ]);

    $response = $this->actingAs($officer)->getJson("/api/v1/sdt/hq-workspace?mission_id={$missionA->id}");

    $response->assertOk();
    $alertIds = collect($response->json('data.assigned_alerts'))->pluck('id');
    expect($alertIds)->toEqual(collect([$alertA->id]));
});

// --- FR-SDT-019, FR-SDT-020, FR-SDT-023: Config screens ------------------

it('lets a System Administrator manage alert field configuration (FR-SDT-019)', function () {
    $ministry = Ministry::factory()->create();
    $admin = sdtSystemAdministrator();

    $storeResponse = $this->actingAs($admin)->postJson('/api/v1/sdt/config/alert-fields', [
        'ministry_id' => $ministry->id,
        'value' => 'supply_chain_disruption',
    ]);
    $storeResponse->assertCreated();
    $entryId = $storeResponse->json('data.id');

    $this->assertDatabaseHas('master_data_entries', [
        'id' => $entryId,
        'category' => 'alert_intelligence_type',
        'value' => 'supply_chain_disruption',
    ]);

    $this->actingAs($admin)
        ->getJson('/api/v1/sdt/config/alert-fields')
        ->assertOk()
        ->assertJsonFragment(['value' => 'supply_chain_disruption']);

    $this->actingAs($admin)
        ->patchJson("/api/v1/sdt/config/alert-fields/{$entryId}", ['active' => false])
        ->assertOk()
        ->assertJsonPath('data.active', false);
});

it('rejects a Ministry HQ Officer from the alert field config screen', function () {
    $ministry = Ministry::factory()->create();
    $officer = ministryHqOfficer($ministry);

    $this->actingAs($officer)
        ->getJson('/api/v1/sdt/config/alert-fields')
        ->assertForbidden();
});

it('lets a System Administrator manage inquiry setting configuration (FR-SDT-020)', function () {
    $ministry = Ministry::factory()->create();
    $admin = sdtSystemAdministrator();

    $storeResponse = $this->actingAs($admin)->postJson('/api/v1/sdt/config/inquiry-settings', [
        'category' => 'inquiry_category',
        'ministry_id' => $ministry->id,
        'value' => 'Regulatory Compliance Question',
    ]);

    $storeResponse->assertCreated();

    $this->actingAs($admin)
        ->getJson('/api/v1/sdt/config/inquiry-settings')
        ->assertOk()
        ->assertJsonFragment(['value' => 'Regulatory Compliance Question']);
});

it('rejects an inquiry setting outside the configured category set', function () {
    $ministry = Ministry::factory()->create();
    $admin = sdtSystemAdministrator();

    $this->actingAs($admin)->postJson('/api/v1/sdt/config/inquiry-settings', [
        'category' => 'directive_type',
        'ministry_id' => $ministry->id,
        'value' => 'Not Allowed',
    ])->assertStatus(422);
});

it('lets a System Administrator manage the referral organisation registry (FR-SDT-023)', function () {
    $ministry = Ministry::factory()->create();
    $admin = sdtSystemAdministrator();

    $storeResponse = $this->actingAs($admin)->postJson('/api/v1/sdt/config/referral-organisations', [
        'ministry_id' => $ministry->id,
        'name' => 'Kenya Export Promotion Council',
    ]);
    $storeResponse->assertCreated();
    $organisationId = $storeResponse->json('data.id');

    $this->assertDatabaseHas('referral_organisations', [
        'id' => $organisationId,
        'name' => 'Kenya Export Promotion Council',
    ]);

    $this->actingAs($admin)
        ->patchJson("/api/v1/sdt/config/referral-organisations/{$organisationId}", ['active' => false])
        ->assertOk()
        ->assertJsonPath('data.active', false);
});

it('rejects a Ministry PS from the referral organisation config screen', function () {
    $ministry = Ministry::factory()->create();
    $ps = ministryPs($ministry);

    $this->actingAs($ps)
        ->getJson('/api/v1/sdt/config/referral-organisations')
        ->assertForbidden();
});

it('lets a System Administrator manage AIE budget code configuration (TC-FR-SDT-022)', function () {
    $ministry = Ministry::factory()->create();
    $admin = sdtSystemAdministrator();

    $storeResponse = $this->actingAs($admin)->postJson('/api/v1/sdt/config/aie-budget-codes', [
        'ministry_id' => $ministry->id,
        'value' => '2210505 — Trade Shows and Exhibitions',
    ]);
    $storeResponse->assertCreated();
    $entryId = $storeResponse->json('data.id');

    $this->assertDatabaseHas('master_data_entries', [
        'id' => $entryId,
        'category' => 'aie_budget_code',
        'value' => '2210505 — Trade Shows and Exhibitions',
    ]);

    $this->actingAs($admin)
        ->getJson('/api/v1/sdt/config/aie-budget-codes')
        ->assertOk()
        ->assertJsonFragment(['value' => '2210505 — Trade Shows and Exhibitions']);

    $this->actingAs($admin)
        ->patchJson("/api/v1/sdt/config/aie-budget-codes/{$entryId}", ['active' => false])
        ->assertOk()
        ->assertJsonPath('data.active', false);
});

it('rejects a Ministry PS from the AIE budget code config screen', function () {
    $ministry = Ministry::factory()->create();
    $ps = ministryPs($ministry);

    $this->actingAs($ps)
        ->getJson('/api/v1/sdt/config/aie-budget-codes')
        ->assertForbidden();
});

it('returns a 404, not another category\'s row, for a mismatched AIE budget code update', function () {
    $ministry = Ministry::factory()->create();
    $admin = sdtSystemAdministrator();

    $alertField = $this->actingAs($admin)->postJson('/api/v1/sdt/config/alert-fields', [
        'ministry_id' => $ministry->id,
        'value' => 'currency_controls',
    ])->json('data.id');

    $this->actingAs($admin)
        ->patchJson("/api/v1/sdt/config/aie-budget-codes/{$alertField}", ['active' => false])
        ->assertNotFound();
});

// --- FR-SDT-007, FR-SDT-008, FR-SDT-009: PS console compliance views -----

it('lets a Ministry PS view the report compliance console (TC-FR-SDT-007)', function () {
    $ministry = Ministry::factory()->create();
    $ps = ministryPs($ministry);

    $response = $this->actingAs($ps)->getJson('/api/v1/sdt/reports/compliance');

    $response->assertOk()
        ->assertJsonStructure(['data' => ['period_label', 'missions', 'summary']]);
});

it('rejects a Ministry HQ Officer from the report compliance console', function () {
    $ministry = Ministry::factory()->create();
    $officer = ministryHqOfficer($ministry);

    $this->actingAs($officer)
        ->getJson('/api/v1/sdt/reports/compliance')
        ->assertForbidden();
});

it('lets a Ministry PS view the directive overview (TC-FR-SDT-008)', function () {
    $ministry = Ministry::factory()->create();
    $ps = ministryPs($ministry);

    $response = $this->actingAs($ps)->getJson('/api/v1/sdt/directives/overview');

    $response->assertOk()
        ->assertJsonStructure(['data' => ['summary' => ['issued', 'in_progress', 'overdue', 'completed'], 'recent_directives', 'stale_directives']]);
});

it('lets a Ministry PS view the directive compliance dashboard (TC-FR-SDT-009)', function () {
    $ministry = Ministry::factory()->create();
    $ps = ministryPs($ministry);

    $response = $this->actingAs($ps)->getJson('/api/v1/sdt/directives/compliance');

    $response->assertOk()
        ->assertJsonStructure(['data' => ['missions', 'summary']]);
});

it('rejects a Ministry HQ Officer from the directive overview and compliance endpoints', function () {
    $ministry = Ministry::factory()->create();
    $officer = ministryHqOfficer($ministry);

    $this->actingAs($officer)->getJson('/api/v1/sdt/directives/overview')->assertForbidden();
    $this->actingAs($officer)->getJson('/api/v1/sdt/directives/compliance')->assertForbidden();
});

// --- ADR-006: leadership switches shared by the PS and the Ministry Administrator ---

function sdtMinistryAdministrator(Ministry $ministry): User
{
    return User::factory()->create([
        'role_id' => sdtRole('Ministry Administrator', '1')->id,
        'ministry_id' => $ministry->id,
        'mission_id' => null,
    ]);
}

it('lets the Ministry Administrator switch the Acting PS on and off for its own department (TC-FR-SDT-004-MA)', function () {
    $ministry = Ministry::factory()->create();
    $administrator = sdtMinistryAdministrator($ministry);
    $officer = ministryHqOfficer($ministry);

    $this->actingAs($administrator)->postJson('/api/v1/sdt/acting-ps/activate', ['user_id' => $officer->id])->assertOk();
    expect($officer->fresh()->role->name)->toBe('Acting PS');

    $this->actingAs($administrator)->postJson('/api/v1/sdt/acting-ps/deactivate')->assertOk();
    expect($officer->fresh()->role->name)->toBe('Ministry HQ Officer');
});

it('pins every Acting PS actor but a System Administrator to its own department (TC-FR-SDT-006-MA)', function () {
    $ministry = Ministry::factory()->create();
    $otherMinistry = Ministry::factory()->create();
    $foreignOfficer = ministryHqOfficer($otherMinistry);

    $this->actingAs(sdtMinistryAdministrator($ministry))
        ->postJson('/api/v1/sdt/acting-ps/activate', ['user_id' => $foreignOfficer->id])
        ->assertStatus(422);

    $this->actingAs(sdtSystemAdministrator())->postJson('/api/v1/sdt/acting-ps/activate', ['user_id' => $foreignOfficer->id])->assertOk();

    // A PS naming another department's ministry_id can no longer end its Acting PS.
    $this->actingAs(ministryPs($ministry))
        ->postJson('/api/v1/sdt/acting-ps/deactivate', ['ministry_id' => $otherMinistry->id])
        ->assertStatus(422);
    expect($otherMinistry->fresh()->acting_ps_active)->toBeTrue();
});

it('lets the PS or the Ministry Administrator switch the Designated Deputy on and off (TC-FR-SDT-003)', function () {
    $ministry = Ministry::factory()->create();
    $deputy = ministryHqOfficer($ministry);

    $this->actingAs(ministryPs($ministry))->postJson('/api/v1/sdt/designated-deputy/activate', ['user_id' => $deputy->id])
        ->assertOk()->assertJsonPath('data.designated_deputy_user_id', $deputy->id);
    expect($ministry->fresh()->designated_deputy_active)->toBeTrue();

    $this->actingAs(sdtMinistryAdministrator($ministry))->postJson('/api/v1/sdt/designated-deputy/deactivate')->assertOk();
    expect($ministry->fresh()->designated_deputy_active)->toBeFalse();

    expect(AuditLog::where('action', 'designated_deputy.activated')->where('ministry_id', $ministry->id)->exists())->toBeTrue();
    expect(AuditLog::where('action', 'designated_deputy.deactivated')->where('ministry_id', $ministry->id)->exists())->toBeTrue();
});

it('rejects a cross-department or unauthorised Designated Deputy switch (TC-FR-SDT-003-B)', function () {
    $ministry = Ministry::factory()->create();
    $foreignDeputy = ministryHqOfficer(Ministry::factory()->create());

    $this->actingAs(sdtMinistryAdministrator($ministry))
        ->postJson('/api/v1/sdt/designated-deputy/activate', ['user_id' => $foreignDeputy->id])
        ->assertStatus(422);

    $this->actingAs(ministryHqOfficer($ministry))
        ->postJson('/api/v1/sdt/designated-deputy/activate', ['user_id' => ministryHqOfficer($ministry)->id])
        ->assertForbidden();
});
