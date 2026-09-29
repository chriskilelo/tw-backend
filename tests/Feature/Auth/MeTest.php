<?php

use App\Enums\LanguagePreference;
use App\Models\AuditLog;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('returns the authenticated user\'s profile', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.role.id', $user->role_id);
});

it('embeds the mission and ministry names for a mission-scoped user (ProfilePage)', function () {
    $mission = Mission::factory()->create(['name' => 'London', 'host_country' => 'United Kingdom']);
    $ministry = Ministry::factory()->create(['name' => 'State Department for Trade']);
    $user = User::factory()->create(['mission_id' => $mission->id, 'ministry_id' => $ministry->id]);

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.user.mission.name', 'London')
        ->assertJsonPath('data.user.mission.host_country', 'United Kingdom')
        ->assertJsonPath('data.user.mission.time_zone', $mission->time_zone)
        ->assertJsonPath('data.user.ministry.name', 'State Department for Trade');
});

it('returns null mission/ministry for a platform-scoped user with neither', function () {
    $user = User::factory()->create(['mission_id' => null, 'ministry_id' => null]);

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.user.mission', null)
        ->assertJsonPath('data.user.ministry', null);
});

it('rejects an unauthenticated request to /me', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('updates the language preference to sw (TC-FR-I18N-002, TC-NFR-I18N-001)', function () {
    $user = User::factory()->create(['language_preference' => LanguagePreference::English]);

    $this->actingAs($user)
        ->patchJson('/api/v1/me/preferences', ['language_preference' => 'sw'])
        ->assertOk()
        ->assertJsonPath('data.user.language_preference', 'sw');

    expect($user->fresh()->language_preference)->toBe(LanguagePreference::Swahili);
});

it('rejects an unsupported language preference (TC-NFR-I18N-001-B)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patchJson('/api/v1/me/preferences', ['language_preference' => 'fr'])
        ->assertStatus(422);

    expect($user->fresh()->language_preference)->not->toBe('fr');
});

it('updates email notification preferences (TC-FR-NOTIF-006)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patchJson('/api/v1/me/preferences', [
            'email_notification_preferences' => ['alert_routed' => false],
        ])
        ->assertOk()
        ->assertJsonPath('data.user.email_notification_preferences.alert_routed', false);
});

it('returns a null avatar_url when no photo has been uploaded (TC-FR-AUTH-019-A)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.user.avatar_url', null);
});

it('uploads a profile photo and stores it under a UUID filename, not the original name (TC-FR-AUTH-019-B)', function () {
    Storage::fake('uploads');
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('me.jpg', 100, 'image/jpeg');

    $response = $this->actingAs($user)->postJson('/api/v1/me/avatar', ['file' => $file]);

    $response->assertOk();
    expect($response->json('data.user.avatar_url'))->not->toBeNull();

    $storedPath = $user->fresh()->avatar_path;
    expect($storedPath)->not->toContain('me.jpg');
    expect(basename($storedPath))->toMatch('/^[0-9a-f-]{36}\.jpg$/');
    Storage::disk('uploads')->assertExists($storedPath);
});

it('replacing an existing avatar deletes the old file from disk (TC-FR-AUTH-019-C)', function () {
    Storage::fake('uploads');
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/me/avatar', [
        'file' => UploadedFile::fake()->create('first.jpg', 100, 'image/jpeg'),
    ]);
    $firstPath = $user->fresh()->avatar_path;

    $this->actingAs($user)->postJson('/api/v1/me/avatar', [
        'file' => UploadedFile::fake()->create('second.png', 100, 'image/png'),
    ]);
    $secondPath = $user->fresh()->avatar_path;

    expect($secondPath)->not->toBe($firstPath);
    Storage::disk('uploads')->assertMissing($firstPath);
    Storage::disk('uploads')->assertExists($secondPath);
});

it('rejects an oversized avatar upload (TC-FR-AUTH-019-D)', function () {
    Storage::fake('uploads');
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('too-big.jpg', 3000, 'image/jpeg');

    $this->actingAs($user)
        ->postJson('/api/v1/me/avatar', ['file' => $file])
        ->assertStatus(422);

    expect($user->fresh()->avatar_path)->toBeNull();
});

it('rejects a disallowed file extension (TC-FR-AUTH-019-E)', function () {
    Storage::fake('uploads');
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('me.pdf', 100, 'application/pdf');

    $this->actingAs($user)
        ->postJson('/api/v1/me/avatar', ['file' => $file])
        ->assertStatus(422);

    expect($user->fresh()->avatar_path)->toBeNull();
});

it('removes the profile photo and deletes the file from disk (TC-FR-AUTH-019-F)', function () {
    Storage::fake('uploads');
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/me/avatar', [
        'file' => UploadedFile::fake()->create('me.jpg', 100, 'image/jpeg'),
    ]);
    $path = $user->fresh()->avatar_path;

    $this->actingAs($user)
        ->deleteJson('/api/v1/me/avatar')
        ->assertNoContent();

    expect($user->fresh()->avatar_path)->toBeNull();
    Storage::disk('uploads')->assertMissing($path);
});

it('removing a photo that was never set is a harmless no-op (TC-FR-AUTH-019-G)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->deleteJson('/api/v1/me/avatar')
        ->assertNoContent();

    expect($user->fresh()->avatar_path)->toBeNull();
});

it('removing a profile photo does not alter historical audit log attribution (TC-FR-AUTH-019-H, BR-024)', function () {
    Storage::fake('uploads');
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/me/avatar', [
        'file' => UploadedFile::fake()->create('me.jpg', 100, 'image/jpeg'),
    ]);
    $this->actingAs($user)->deleteJson('/api/v1/me/avatar');

    // The generic ModelObserver (Session 9) audit-logs both mutations under the same
    // user_id regardless of avatar state — nothing about removing a photo rewrites or
    // detaches any prior audit_logs row's attribution.
    expect(AuditLog::where('user_id', $user->id)->where('action', 'user.updated')->count())
        ->toBeGreaterThanOrEqual(2);
});
