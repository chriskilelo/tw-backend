<?php

use App\Enums\UserStatus;
use App\Jobs\SendAccountActivationEmail;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * ADR-006: two-tier administration — FR-AUTH-020 (Ministry Administrator
 * role and scope), FR-AUTH-021 (department user management), FR-AUTH-024
 * (seat rules, BR-026/028/029), FR-AUTH-025 (display titles), plus the
 * department registry and mission-posting endpoints.
 */
function twoTierRole(string $name, string $layer = '2', string $scope = 'ministry'): Role
{
    return Role::query()->firstOrCreate(['name' => $name], ['layer' => $layer, 'scope' => $scope]);
}

function twoTierUser(string $roleName, ?Ministry $ministry = null, ?Mission $mission = null): User
{
    return User::factory()->create([
        'role_id' => twoTierRole($roleName)->id,
        'ministry_id' => $ministry?->id,
        'mission_id' => $mission?->id,
    ]);
}

function twoTierMinistryAdministrator(Ministry $ministry): User
{
    return twoTierUser('Ministry Administrator', $ministry);
}

function twoTierSystemAdministrator(): User
{
    return User::factory()->create([
        'role_id' => twoTierRole('System Administrator', '1', 'platform')->id,
    ]);
}

// --- FR-AUTH-021: department user management ------------------------------

it('lists only the Ministry Administrator\'s own department (TC-FR-AUTH-021-A)', function () {
    $sdt = Ministry::factory()->create();
    $other = Ministry::factory()->create();
    $admin = twoTierMinistryAdministrator($sdt);
    $ownOfficer = twoTierUser('Ministry HQ Officer', $sdt);
    $foreignOfficer = twoTierUser('Ministry HQ Officer', $other);

    $ids = collect($this->actingAs($admin)->getJson('/api/v1/users?per_page=100')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($ownOfficer->id)->not->toContain($foreignOfficer->id);
});

it('creates an account pinned to its own department and signs the activation email (TC-FR-AUTH-021-B)', function () {
    Queue::fake();
    $sdt = Ministry::factory()->create();
    $admin = twoTierMinistryAdministrator($sdt);
    $mission = Mission::factory()->create();

    $response = $this->actingAs($admin)->postJson('/api/v1/users', [
        'full_name' => 'New Attache',
        'email' => 'new.attache@example.test',
        'role_id' => twoTierRole('Ministry Attache', '2', 'mission')->id,
        'mission_id' => $mission->id,
    ])->assertCreated();

    expect($response->json('data.ministry.id'))->toBe($sdt->id);
    Queue::assertPushed(SendAccountActivationEmail::class, fn ($job) => $job->email === 'new.attache@example.test'
        && str_contains((string) $job->createdBy, $admin->full_name));
});

it('rejects creating an account in another department (TC-FR-AUTH-021-C)', function () {
    $admin = twoTierMinistryAdministrator(Ministry::factory()->create());

    $this->actingAs($admin)->postJson('/api/v1/users', [
        'full_name' => 'Elsewhere Officer',
        'email' => 'elsewhere@example.test',
        'role_id' => twoTierRole('Ministry HQ Officer')->id,
        'ministry_id' => Ministry::factory()->create()->id,
    ])->assertStatus(422);

    expect(User::where('email', 'elsewhere@example.test')->exists())->toBeFalse();
});

it('rejects assigning a role reserved to the System Administrator (TC-FR-AUTH-021-D)', function (string $roleName, string $scope) {
    $admin = twoTierMinistryAdministrator(Ministry::factory()->create());

    $this->actingAs($admin)->postJson('/api/v1/users', [
        'full_name' => 'Reserved Role',
        'email' => 'reserved@example.test',
        'role_id' => twoTierRole($roleName, '1', $scope)->id,
    ])->assertStatus(422);
})->with([
    'System Administrator' => ['System Administrator', 'platform'],
    'Ministry Administrator' => ['Ministry Administrator', 'ministry'],
    'Acting PS' => ['Acting PS', 'ministry'],
    'MFA HQ Officer' => ['MFA HQ Officer', 'platform'],
]);

it('points a direct PS appointment to the approval workflow (TC-FR-AUTH-021-E)', function () {
    $admin = twoTierMinistryAdministrator(Ministry::factory()->create());

    $response = $this->actingAs($admin)->postJson('/api/v1/users', [
        'full_name' => 'Would-be PS',
        'email' => 'ps@example.test',
        'role_id' => twoTierRole('Ministry PS')->id,
    ])->assertStatus(422);

    expect(implode(' ', $response->json('errors')))->toContain('approval');
});

it('reports another department\'s account as not found (TC-FR-AUTH-021-F)', function () {
    $admin = twoTierMinistryAdministrator(Ministry::factory()->create());
    $foreign = twoTierUser('Ministry HQ Officer', Ministry::factory()->create());

    $this->actingAs($admin)->getJson("/api/v1/users/{$foreign->id}")->assertNotFound();
    $this->actingAs($admin)->patchJson("/api/v1/users/{$foreign->id}", ['full_name' => 'Renamed'])->assertNotFound();
    $this->actingAs($admin)->postJson("/api/v1/users/{$foreign->id}/deactivate")->assertNotFound();
    $this->actingAs($admin)->getJson('/api/v1/users/'.twoTierSystemAdministrator()->id)->assertNotFound();
});

it('cannot manage a fellow Ministry Administrator (TC-FR-AUTH-021-G)', function () {
    $sdt = Ministry::factory()->create();
    $admin = twoTierMinistryAdministrator($sdt);
    $colleague = twoTierMinistryAdministrator($sdt);

    $this->actingAs($admin)->patchJson("/api/v1/users/{$colleague->id}", ['full_name' => 'Renamed'])->assertForbidden();
    $this->actingAs($admin)->postJson("/api/v1/users/{$colleague->id}/deactivate")->assertForbidden();
});

it('cannot add or remove a PS outside the approval workflow (TC-FR-AUTH-021-H)', function () {
    $sdt = Ministry::factory()->create();
    $admin = twoTierMinistryAdministrator($sdt);
    $ps = twoTierUser('Ministry PS', $sdt);
    $officer = twoTierUser('Ministry HQ Officer', $sdt);

    $this->actingAs($admin)->postJson("/api/v1/users/{$ps->id}/deactivate")->assertStatus(422);
    $this->actingAs($admin)->patchJson("/api/v1/users/{$ps->id}", ['role_id' => twoTierRole('Ministry HQ Officer')->id])->assertStatus(422);
    $this->actingAs($admin)->patchJson("/api/v1/users/{$officer->id}", ['role_id' => twoTierRole('Ministry PS')->id])->assertStatus(422);

    expect($ps->fresh()->status)->toBe(UserStatus::Active);
});

it('cannot move an account out of its department (TC-FR-AUTH-021-I)', function () {
    $sdt = Ministry::factory()->create();
    $admin = twoTierMinistryAdministrator($sdt);
    $attache = twoTierUser('Ministry Attache', $sdt, Mission::factory()->create());

    $this->actingAs($admin)->patchJson("/api/v1/users/{$attache->id}", ['ministry_id' => null])->assertStatus(422);

    expect($attache->fresh()->ministry_id)->toBe($sdt->id);
});

it('manages an ordinary account in its own department (TC-FR-AUTH-021-J)', function () {
    $sdt = Ministry::factory()->create();
    $admin = twoTierMinistryAdministrator($sdt);
    $officer = twoTierUser('Ministry HQ Officer', $sdt);

    $this->actingAs($admin)->patchJson("/api/v1/users/{$officer->id}", ['full_name' => 'Renamed Officer'])->assertOk();
    $this->actingAs($admin)->postJson("/api/v1/users/{$officer->id}/deactivate")->assertOk();
    $this->actingAs($admin)->postJson("/api/v1/users/{$officer->id}/reactivate")->assertOk();

    expect($officer->fresh()->full_name)->toBe('Renamed Officer');
});

// --- FR-AUTH-024: seat rules ------------------------------------------------

it('allows at most three Ministry Administrators per department (TC-FR-AUTH-024-A)', function () {
    $sdt = Ministry::factory()->create();
    $systemAdministrator = twoTierSystemAdministrator();
    twoTierMinistryAdministrator($sdt);
    twoTierMinistryAdministrator($sdt);
    twoTierMinistryAdministrator($sdt);

    $payload = fn (Ministry $ministry, string $email) => [
        'full_name' => 'Fourth Administrator',
        'email' => $email,
        'role_id' => twoTierRole('Ministry Administrator')->id,
        'ministry_id' => $ministry->id,
    ];

    $this->actingAs($systemAdministrator)->postJson('/api/v1/users', $payload($sdt, 'fourth@example.test'))->assertStatus(422);
    $this->actingAs($systemAdministrator)->postJson('/api/v1/users', $payload(Ministry::factory()->create(), 'first@example.test'))->assertCreated();
});

it('allows only one Principal Secretary per department (TC-FR-AUTH-024-B)', function () {
    $sdt = Ministry::factory()->create();
    twoTierUser('Ministry PS', $sdt);

    $this->actingAs(twoTierSystemAdministrator())->postJson('/api/v1/users', [
        'full_name' => 'Second PS',
        'email' => 'second.ps@example.test',
        'role_id' => twoTierRole('Ministry PS')->id,
        'ministry_id' => $sdt->id,
    ])->assertStatus(422);
});

it('never leaves the platform without an active System Administrator (TC-FR-AUTH-024-C)', function () {
    $only = twoTierSystemAdministrator();

    $this->actingAs($only)->patchJson("/api/v1/users/{$only->id}", ['role_id' => twoTierRole('MFA HQ Officer', '1', 'platform')->id])->assertStatus(422);

    $second = twoTierSystemAdministrator();
    $this->actingAs($only)->postJson("/api/v1/users/{$second->id}/deactivate")->assertOk();
    $this->actingAs($only)->postJson("/api/v1/users/{$only->id}/deactivate")->assertStatus(422);
});

// --- FR-AUTH-025: display titles -------------------------------------------

it('returns the role display title without using it for authorisation (TC-FR-AUTH-025)', function () {
    $sdt = Ministry::factory()->create();
    twoTierRole('Ministry Administrator')->forceFill(['display_title' => 'Department Keeper'])->save();
    $admin = twoTierMinistryAdministrator($sdt);

    $this->actingAs($admin)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.role.name', 'Ministry Administrator')
        ->assertJsonPath('data.role.display_title', 'Department Keeper');

    $this->actingAs($admin)->getJson("/api/v1/users/{$admin->id}")->assertJsonPath('data.role.display_title', 'Department Keeper');
});

it('records a home department for a System Administrator only (TC-FR-AUTH-025-B)', function () {
    $sdt = Ministry::factory()->create();
    $systemAdministrator = twoTierSystemAdministrator();

    $this->actingAs($systemAdministrator)->postJson('/api/v1/users', [
        'full_name' => 'Second System Administrator',
        'email' => 'second.sa@example.test',
        'role_id' => twoTierRole('System Administrator', '1', 'platform')->id,
        'home_ministry_id' => $sdt->id,
    ])->assertCreated()->assertJsonPath('data.home_ministry.id', $sdt->id)->assertJsonPath('data.ministry', null);

    $this->actingAs($systemAdministrator)->postJson('/api/v1/users', [
        'full_name' => 'Officer With Home',
        'email' => 'officer.home@example.test',
        'role_id' => twoTierRole('Ministry HQ Officer')->id,
        'ministry_id' => $sdt->id,
        'home_ministry_id' => $sdt->id,
    ])->assertStatus(422);
});

// --- Department registry ---------------------------------------------------

it('lists only the Ministry Administrator\'s own department and lets only a System Administrator onboard one (TC-FR-AUTH-020-A)', function () {
    $sdt = Ministry::factory()->create();
    Ministry::factory()->create();
    $admin = twoTierMinistryAdministrator($sdt);

    expect(collect($this->actingAs($admin)->getJson('/api/v1/ministries')->assertOk()->json('data'))->pluck('id')->all())->toBe([$sdt->id]);

    $this->actingAs($admin)->postJson('/api/v1/ministries', ['name' => 'State Department for Industry'])->assertForbidden();
    $this->actingAs(twoTierSystemAdministrator())->postJson('/api/v1/ministries', ['name' => 'State Department for Industry'])
        ->assertCreated()->assertJsonPath('data.name', 'State Department for Industry');
});

// --- Mission postings (BR-004) ---------------------------------------------

it('posts, reassigns and clears its own department\'s attache at a mission (TC-FR-AUTH-021-K)', function () {
    $sdt = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $admin = twoTierMinistryAdministrator($sdt);
    $first = twoTierUser('Ministry Attache', $sdt, $mission);
    $second = twoTierUser('Ministry Attache', $sdt, $mission);

    $this->actingAs($admin)->patchJson("/api/v1/mission-links/{$mission->id}", ['active_attache_user_id' => $first->id])->assertOk();
    $this->actingAs($admin)->patchJson("/api/v1/mission-links/{$mission->id}", ['active_attache_user_id' => $second->id])
        ->assertOk()->assertJsonPath('data.active_attache.id', $second->id);
    $this->actingAs($admin)->patchJson("/api/v1/mission-links/{$mission->id}", ['active_attache_user_id' => null])
        ->assertOk()->assertJsonPath('data.active_attache_user_id', null);

    expect(MissionMinistryLink::where('mission_id', $mission->id)->where('ministry_id', $sdt->id)->count())->toBe(1);
});

it('rejects a posting in another department or of an ineligible user (TC-FR-AUTH-021-L)', function () {
    $sdt = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $admin = twoTierMinistryAdministrator($sdt);
    $foreignAttache = twoTierUser('Ministry Attache', Ministry::factory()->create(), $mission);
    $wrongMissionAttache = twoTierUser('Ministry Attache', $sdt, Mission::factory()->create());

    $this->actingAs($admin)->patchJson("/api/v1/mission-links/{$mission->id}", [
        'ministry_id' => $foreignAttache->ministry_id,
        'active_attache_user_id' => $foreignAttache->id,
    ])->assertStatus(422);
    $this->actingAs($admin)->patchJson("/api/v1/mission-links/{$mission->id}", ['active_attache_user_id' => $foreignAttache->id])->assertStatus(422);
    $this->actingAs($admin)->patchJson("/api/v1/mission-links/{$mission->id}", ['active_attache_user_id' => $wrongMissionAttache->id])->assertStatus(422);
});

it('keeps mission records themselves System Administrator only (TC-FR-AUTH-020-B)', function () {
    $admin = twoTierMinistryAdministrator(Ministry::factory()->create());
    $mission = Mission::factory()->create();

    $this->actingAs($admin)->getJson('/api/v1/missions')->assertOk();
    $this->actingAs($admin)->patchJson("/api/v1/missions/{$mission->id}", ['name' => 'Renamed'])->assertForbidden();
    $this->actingAs($admin)->postJson("/api/v1/missions/{$mission->id}/deactivate")->assertForbidden();
});

it('offers a Ministry Administrator only the roles it may assign (TC-FR-AUTH-021-M)', function () {
    foreach (['Ministry Attache', 'Ministry HQ Officer', 'Ministry PS', 'System Administrator', 'Ministry Administrator'] as $name) {
        twoTierRole($name);
    }

    $names = collect($this->actingAs(twoTierMinistryAdministrator(Ministry::factory()->create()))->getJson('/api/v1/roles')->assertOk()->json('data'))->pluck('name');

    expect($names)->toContain('Ministry Attache')->toContain('Ministry HQ Officer')
        ->not->toContain('Ministry PS')->not->toContain('System Administrator')->not->toContain('Ministry Administrator');

    expect(collect($this->actingAs(twoTierSystemAdministrator())->getJson('/api/v1/roles')->json('data'))->pluck('name'))->toContain('Ministry PS');
    $this->actingAs(twoTierUser('Ministry HQ Officer', Ministry::factory()->create()))->getJson('/api/v1/roles')->assertForbidden();
});
