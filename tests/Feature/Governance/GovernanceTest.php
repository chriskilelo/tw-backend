<?php

use App\Models\Alert;
use App\Models\Directive;
use App\Models\Inquiry;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\Role;
use App\Models\User;

/**
 * CLAUDE.md Section 4, Rule 2 / FR-HOM-001 to 003, FR-MFA-001 to 003,
 * BR-020, FR-AUTH-017, DES-003. Session 13: read-only governance
 * dashboards. Every write-method call against these four roles must be
 * rejected at the policy layer (BasePolicy::before()) regardless of which
 * engine the write targets — TC-LEG-002 below checks this against the
 * unrelated Alerts engine, not just the governance endpoints themselves.
 */
function governanceRole(string $name, string $layer = '1', string $scope = 'mission'): Role
{
    return Role::query()->firstOrCreate(['name' => $name], ['layer' => $layer, 'scope' => $scope]);
}

function headOfMission(Mission $mission): User
{
    return User::factory()->create([
        'role_id' => governanceRole('Head of Mission')->id,
        'mission_id' => $mission->id,
        'ministry_id' => null,
    ]);
}

function deputyHeadOfMission(Mission $mission): User
{
    return User::factory()->create([
        'role_id' => governanceRole('Deputy Head of Mission')->id,
        'mission_id' => $mission->id,
        'ministry_id' => null,
    ]);
}

function mfaHqOfficer(): User
{
    return User::factory()->create([
        'role_id' => governanceRole('MFA HQ Officer', '1', 'platform')->id,
        'mission_id' => null,
        'ministry_id' => null,
    ]);
}

function mfaPrincipalSecretary(): User
{
    return User::factory()->create([
        'role_id' => governanceRole('MFA Principal Secretary', '1', 'platform')->id,
        'mission_id' => null,
        'ministry_id' => null,
    ]);
}

// --- FR-HOM-001: Mission Activity Feed ---------------------------------

it('lets a Head of Mission view their own mission activity feed (TC-FR-HOM-001)', function () {
    $ministry = Ministry::factory()->create();
    $ownMission = Mission::factory()->create();
    $otherMission = Mission::factory()->create();
    $hom = headOfMission($ownMission);

    $ownAlert = Alert::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $ownMission->id]);
    $ownInquiry = Inquiry::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $ownMission->id]);
    $foreignAlert = Alert::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $otherMission->id]);

    $response = $this->actingAs($hom)->getJson('/api/v1/mission-activity');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($ownAlert->id)
        ->toContain($ownInquiry->id)
        ->not->toContain($foreignAlert->id);
});

it('lets a Deputy Head of Mission view the same activity feed as the Head of Mission (TC-FR-HOM-003)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $dhom = deputyHeadOfMission($mission);

    Alert::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id]);

    $this->actingAs($dhom)
        ->getJson('/api/v1/mission-activity')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('filters the mission activity feed by ministry_id (FR-HOM-001 AC3)', function () {
    $ministryA = Ministry::factory()->create();
    $ministryB = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $hom = headOfMission($mission);

    $itemA = Alert::factory()->create(['ministry_id' => $ministryA->id, 'mission_id' => $mission->id]);
    Alert::factory()->create(['ministry_id' => $ministryB->id, 'mission_id' => $mission->id]);

    $response = $this->actingAs($hom)->getJson("/api/v1/mission-activity?ministry_id={$ministryA->id}");

    $response->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.id'))->toBe($itemA->id);
});

it('rejects a Ministry Attache from viewing the mission activity feed', function () {
    $mission = Mission::factory()->create();
    $attache = User::factory()->create([
        'role_id' => governanceRole('Ministry Attache', '2', 'mission')->id,
        'mission_id' => $mission->id,
    ]);

    $this->actingAs($attache)
        ->getJson('/api/v1/mission-activity')
        ->assertForbidden();
});

it('returns current and prior period summary counts by type and status (TC-FR-HOM-002)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $hom = headOfMission($mission);

    Alert::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id]);
    Inquiry::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id]);
    Directive::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id]);

    $response = $this->actingAs($hom)->getJson('/api/v1/mission-activity/summary');

    $response->assertOk();
    expect($response->json('data.current_period.total'))->toBe(3)
        ->and($response->json('data.prior_period.total'))->toBe(0)
        ->and($response->json('data.current_period.by_type'))->toHaveKeys(['alert', 'inquiry', 'directive']);
});

// --- FR-MFA-001 to 003: MFA Awareness View ------------------------------

it('lets an MFA HQ Officer view the aggregate cross-mission awareness dashboard (TC-FR-MFA-001)', function () {
    $ministry = Ministry::factory()->create();
    $missionA = Mission::factory()->create();
    $missionB = Mission::factory()->create();
    $officer = mfaHqOfficer();

    Alert::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $missionA->id]);
    Inquiry::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $missionB->id]);

    $response = $this->actingAs($officer)->getJson('/api/v1/mfa-awareness');

    $response->assertOk();
    expect($response->json('data.total'))->toBe(2)
        ->and($response->json('data.by_mission'))->toHaveCount(2);
});

it('lets an MFA HQ Officer drill down into a specific mission (FR-MFA-002)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $officer = mfaHqOfficer();

    Alert::factory()->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id]);

    $this->actingAs($officer)
        ->getJson("/api/v1/mfa-awareness/missions/{$mission->id}")
        ->assertOk()
        ->assertJsonPath('data.current_period.total', 1);
});

it('rejects an MFA HQ Officer from the national overview (FR-MFA-003 is Principal Secretary only)', function () {
    $officer = mfaHqOfficer();

    $this->actingAs($officer)
        ->getJson('/api/v1/mfa-awareness/national-overview')
        ->assertForbidden();
});

it('lets the MFA Principal Secretary view the national overview (FR-MFA-003)', function () {
    Mission::factory()->count(2)->create();
    $ps = mfaPrincipalSecretary();

    $this->actingAs($ps)
        ->getJson('/api/v1/mfa-awareness/national-overview')
        ->assertOk()
        ->assertJsonCount(2, 'data.missions');
});

it('rejects a Head of Mission from the MFA awareness dashboard', function () {
    $mission = Mission::factory()->create();
    $hom = headOfMission($mission);

    $this->actingAs($hom)
        ->getJson('/api/v1/mfa-awareness')
        ->assertForbidden();
});

// --- BR-020 / FR-AUTH-017 / DES-003: structural read-only enforcement --

it('rejects a Head of Mission from creating an alert with a 403, not just hiding the control (TC-LEG-002)', function () {
    $mission = Mission::factory()->create();
    $hom = headOfMission($mission);

    $this->actingAs($hom)
        ->postJson('/api/v1/alerts', ['country' => 'Kenya', 'intelligence_type' => 'opportunities'])
        ->assertForbidden();
});

it('rejects an MFA Principal Secretary from creating an alert with a 403 (TC-LEG-002)', function () {
    $ps = mfaPrincipalSecretary();

    $this->actingAs($ps)
        ->postJson('/api/v1/alerts', ['country' => 'Kenya', 'intelligence_type' => 'opportunities'])
        ->assertForbidden();
});
