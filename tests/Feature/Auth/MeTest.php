<?php

use App\Enums\LanguagePreference;
use App\Models\User;

it('returns the authenticated user\'s profile', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.role.id', $user->role_id);
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
