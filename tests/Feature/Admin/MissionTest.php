<?php

use App\Models\Ministry;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\Role;
use App\Models\User;

function systemAdministratorUser(): User
{
    $role = Role::factory()->create(['name' => 'System Administrator', 'layer' => '1', 'scope' => 'platform']);

    return User::factory()->create(['role_id' => $role->id]);
}

it('lets any authenticated user list missions with limited fields', function () {
    $role = Role::factory()->create(['name' => 'Ministry Attache', 'layer' => '2', 'scope' => 'mission']);
    $user = User::factory()->create(['role_id' => $role->id]);
    Mission::factory()->create(['name' => 'London', 'host_country' => 'United Kingdom']);

    $response = $this->actingAs($user)->getJson('/api/v1/missions');

    $response->assertOk();
    expect($response->json('data.0.name'))->toBe('London');
    expect($response->json('data.0'))->not->toHaveKey('host_country');
});

it('exposes full mission detail to a System Administrator (TC-FR-MISS-001)', function () {
    $admin = systemAdministratorUser();
    Mission::factory()->create(['name' => 'London', 'host_country' => 'United Kingdom']);

    $response = $this->actingAs($admin)->getJson('/api/v1/missions');

    $response->assertOk();
    expect($response->json('data.0.host_country'))->toBe('United Kingdom');
});

it('rejects a non-System-Administrator from creating a mission', function () {
    $role = Role::factory()->create(['name' => 'Ministry Attache', 'layer' => '2', 'scope' => 'mission']);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)->postJson('/api/v1/missions', [
        'name' => 'Nairobi',
        'city' => 'Nairobi',
        'host_country' => 'Kenya',
        'time_zone' => 'Africa/Nairobi',
    ])->assertForbidden();
});

it('marks a mission inactive rather than deleting it (TC-FR-MISS-003)', function () {
    $admin = systemAdministratorUser();
    $mission = Mission::factory()->create(['active' => true]);

    $this->actingAs($admin)
        ->postJson("/api/v1/missions/{$mission->id}/deactivate")
        ->assertOk()
        ->assertJsonPath('data.active', false);

    expect(Mission::query()->find($mission->id))->not->toBeNull();
    expect($mission->fresh()->active)->toBeFalse();
});

it('assigns an attache to a mission-ministry pairing for the first time', function () {
    $admin = systemAdministratorUser();
    $mission = Mission::factory()->create();
    $ministry = Ministry::factory()->create();
    $attacheRole = Role::factory()->create(['name' => 'Ministry Attache', 'layer' => '2', 'scope' => 'mission']);
    $attache = User::factory()->create(['role_id' => $attacheRole->id]);

    $this->actingAs($admin)
        ->patchJson("/api/v1/missions/{$mission->id}", [
            'ministry_id' => $ministry->id,
            'active_attache_user_id' => $attache->id,
        ])
        ->assertOk();

    expect(MissionMinistryLink::query()
        ->where('mission_id', $mission->id)
        ->where('ministry_id', $ministry->id)
        ->where('active_attache_user_id', $attache->id)
        ->exists())->toBeTrue();
});

it('rejects a second active attache link for the same mission and ministry (TC-FR-MISS-004)', function () {
    $admin = systemAdministratorUser();
    $mission = Mission::factory()->create();
    $ministry = Ministry::factory()->create();
    MissionMinistryLink::factory()->create(['mission_id' => $mission->id, 'ministry_id' => $ministry->id]);

    $attacheRole = Role::factory()->create(['name' => 'Ministry Attache', 'layer' => '2', 'scope' => 'mission']);
    $newAttache = User::factory()->create(['role_id' => $attacheRole->id]);

    $response = $this->actingAs($admin)->patchJson("/api/v1/missions/{$mission->id}", [
        'ministry_id' => $ministry->id,
        'active_attache_user_id' => $newAttache->id,
    ]);

    $response->assertStatus(422);

    expect(MissionMinistryLink::query()
        ->where('mission_id', $mission->id)
        ->where('ministry_id', $ministry->id)
        ->count())->toBe(1);
});
