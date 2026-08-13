<?php

use App\Enums\UserStatus;
use App\Jobs\SendAccountActivationEmail;
use App\Models\Mission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

function systemAdministrator(): User
{
    $role = Role::factory()->create(['name' => 'System Administrator', 'layer' => '1', 'scope' => 'platform']);

    return User::factory()->create(['role_id' => $role->id]);
}

it('creates a user with an activation email dispatched (TC-FR-AUTH-001)', function () {
    Queue::fake();

    $admin = systemAdministrator();
    $attacheRole = Role::factory()->create(['name' => 'Ministry Attache', 'layer' => '2', 'scope' => 'mission']);
    $mission = Mission::factory()->create();

    $response = $this->actingAs($admin)->postJson('/api/v1/users', [
        'full_name' => 'Jane Attache',
        'email' => 'jane.attache@example.test',
        'role_id' => $attacheRole->id,
        'mission_id' => $mission->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.email', 'jane.attache@example.test')
        ->assertJsonPath('data.status', UserStatus::ActivationPending->value);

    $user = User::where('email', 'jane.attache@example.test')->first();

    expect($user)->not->toBeNull();
    expect($user->status)->toBe(UserStatus::ActivationPending);

    Queue::assertPushed(SendAccountActivationEmail::class, fn ($job) => $job->email === 'jane.attache@example.test');
});

it('requires a mission assignment when creating a Ministry Attache (TC-FR-AUTH-001-B)', function () {
    $admin = systemAdministrator();
    $attacheRole = Role::factory()->create(['name' => 'Ministry Attache', 'layer' => '2', 'scope' => 'mission']);

    $this->actingAs($admin)->postJson('/api/v1/users', [
        'full_name' => 'Jane Attache',
        'email' => 'jane.attache@example.test',
        'role_id' => $attacheRole->id,
    ])->assertStatus(422);
});

it('rejects a non-System-Administrator from creating a user (TC-FR-AUTH-001-C)', function () {
    $role = Role::factory()->create(['name' => 'Ministry HQ Officer', 'layer' => '2', 'scope' => 'ministry']);
    $user = User::factory()->create(['role_id' => $role->id]);
    $targetRole = Role::factory()->create(['name' => 'Ministry Attache', 'layer' => '2', 'scope' => 'mission']);

    $this->actingAs($user)->postJson('/api/v1/users', [
        'full_name' => 'Jane Attache',
        'email' => 'jane.attache@example.test',
        'role_id' => $targetRole->id,
        'mission_id' => Mission::factory()->create()->id,
    ])->assertForbidden();
});

it('lists and filters the user directory (TC-FR-AUTH-003)', function () {
    $admin = systemAdministrator();
    $role = Role::factory()->create(['name' => 'Ministry Attache', 'layer' => '2', 'scope' => 'mission']);

    User::factory()->create(['role_id' => $role->id, 'status' => UserStatus::Active]);
    User::factory()->create(['role_id' => $role->id, 'status' => UserStatus::Locked]);

    $response = $this->actingAs($admin)->getJson('/api/v1/users?status=locked');

    $response->assertOk();

    expect(collect($response->json('data')))->toHaveCount(1);
    expect($response->json('data.0.status'))->toBe('locked');
    expect($response->json('meta.total'))->toBe(1);
});

it('deactivates a user without deleting the account (TC-FR-AUTH-004)', function () {
    $admin = systemAdministrator();
    $role = Role::factory()->create(['name' => 'Ministry Attache', 'layer' => '2', 'scope' => 'mission']);
    $user = User::factory()->create(['role_id' => $role->id, 'status' => UserStatus::Active]);

    $this->actingAs($admin)
        ->postJson("/api/v1/users/{$user->id}/deactivate")
        ->assertOk()
        ->assertJsonPath('data.status', UserStatus::Deactivated->value);

    $user->refresh();

    expect($user->status)->toBe(UserStatus::Deactivated);
    expect(User::withTrashed()->find($user->id))->not->toBeNull();
});

it('blocks login for a deactivated account (TC-FR-AUTH-004-B)', function () {
    User::factory()->create([
        'email' => 'attache@example.test',
        'password' => Hash::make('CorrectHorse1'),
        'status' => UserStatus::Deactivated,
    ]);

    $this->postJson('/api/v1/login', [
        'email' => 'attache@example.test',
        'password' => 'CorrectHorse1',
    ])->assertStatus(403);

    $this->assertGuest();
});

it('soft-deletes on deactivation, restores on reactivation, and never hard-deletes the row (TC-NFR-DATA-003)', function () {
    $admin = systemAdministrator();
    $role = Role::factory()->create(['name' => 'Ministry Attache', 'layer' => '2', 'scope' => 'mission']);
    $user = User::factory()->create(['role_id' => $role->id, 'status' => UserStatus::Active]);

    $this->actingAs($admin)
        ->postJson("/api/v1/users/{$user->id}/deactivate")
        ->assertOk();

    $trashed = User::withTrashed()->find($user->id);
    expect($trashed)->not->toBeNull();
    expect($trashed->deleted_at)->not->toBeNull();
    expect(User::find($user->id))->toBeNull();

    $this->actingAs($admin)
        ->postJson("/api/v1/users/{$user->id}/reactivate")
        ->assertOk()
        ->assertJsonPath('data.status', UserStatus::Active->value);

    $restored = User::find($user->id);
    expect($restored)->not->toBeNull();
    expect($restored->deleted_at)->toBeNull();
    expect($restored->status)->toBe(UserStatus::Active);
});

it('reactivates a deactivated user, restoring login access (TC-FR-AUTH-005)', function () {
    $admin = systemAdministrator();
    $role = Role::factory()->create(['name' => 'Ministry Attache', 'layer' => '2', 'scope' => 'mission']);
    $user = User::factory()->create([
        'role_id' => $role->id,
        'email' => 'attache@example.test',
        'password' => Hash::make('CorrectHorse1'),
        'status' => UserStatus::Deactivated,
    ]);

    $this->actingAs($admin)
        ->postJson("/api/v1/users/{$user->id}/reactivate")
        ->assertOk()
        ->assertJsonPath('data.status', UserStatus::Active->value);

    $this->postJson('/api/v1/login', [
        'email' => 'attache@example.test',
        'password' => 'CorrectHorse1',
    ])->assertOk();
});
