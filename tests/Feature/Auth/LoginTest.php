<?php

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('grants access and establishes a session for correct credentials (TC-FR-AUTH-007)', function () {
    $user = User::factory()->create([
        'email' => 'attache@example.test',
        'password' => Hash::make('CorrectHorse1'),
    ]);

    $response = $this->postJson('/api/v1/login', [
        'email' => 'attache@example.test',
        'password' => 'CorrectHorse1',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.email', $user->email);

    $this->assertAuthenticatedAs($user->fresh());
});

it('rejects invalid credentials with a generic message (TC-FR-AUTH-007-B)', function () {
    User::factory()->create([
        'email' => 'attache@example.test',
        'password' => Hash::make('CorrectHorse1'),
    ]);

    $response = $this->postJson('/api/v1/login', [
        'email' => 'attache@example.test',
        'password' => 'WrongPassword1',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('errors.0', 'The provided credentials are incorrect.');

    $this->assertGuest();
});

it('locks the account on the 5th consecutive failed login attempt (TC-FR-AUTH-009)', function () {
    $user = User::factory()->create([
        'email' => 'attache@example.test',
        'password' => Hash::make('CorrectHorse1'),
        'failed_login_attempts' => 4,
    ]);

    $this->postJson('/api/v1/login', [
        'email' => 'attache@example.test',
        'password' => 'WrongPassword1',
    ])->assertStatus(401);

    $user->refresh();

    expect($user->failed_login_attempts)->toBe(5);
    expect($user->status)->toBe(UserStatus::Locked);
});

it('returns 423 for a locked account without granting access (TC-FR-AUTH-009-B)', function () {
    User::factory()->create([
        'email' => 'attache@example.test',
        'password' => Hash::make('CorrectHorse1'),
        'status' => UserStatus::Locked,
        'failed_login_attempts' => 5,
    ]);

    $response = $this->postJson('/api/v1/login', [
        'email' => 'attache@example.test',
        'password' => 'CorrectHorse1',
    ]);

    $response->assertStatus(423);
    $this->assertGuest();
});

it('logs successful and failed login attempts to audit_logs (TC-FR-AUTH-012)', function () {
    $user = User::factory()->create([
        'email' => 'attache@example.test',
        'password' => Hash::make('CorrectHorse1'),
    ]);

    $this->postJson('/api/v1/login', [
        'email' => 'attache@example.test',
        'password' => 'WrongPassword1',
    ]);

    $this->postJson('/api/v1/login', [
        'email' => 'attache@example.test',
        'password' => 'CorrectHorse1',
    ]);

    expect(AuditLog::where('user_id', $user->id)->where('action', 'user.login.failed')->count())->toBe(1);
    expect(AuditLog::where('user_id', $user->id)->where('action', 'user.login.succeeded')->count())->toBe(1);
});

/**
 * DES-006: user-facing text SHALL be externalised into language resource
 * files; no user-facing string SHALL be hardcoded into application logic.
 * LoginController currently violates this (known Stage 1 gap, not fixed by
 * this test) by returning literal English strings instead of `__()` calls
 * against a lang resource. This is a deliberate canary, not a correctness
 * assertion: it pins today's known-hardcoded strings so that whoever
 * removes one of them from LoginController.php (by externalising it) is
 * forced to also touch this test, rather than the removal going unnoticed.
 */
it('flags LoginController\'s hardcoded user-facing strings pending externalisation (TC-DES-006)', function () {
    $source = file_get_contents(app_path('Http/Controllers/Api/Auth/LoginController.php'));

    expect($source)->toContain('This account is locked. Reset your password or contact a System Administrator.');
    expect($source)->toContain('This account has been deactivated. Contact a System Administrator.');
    expect($source)->toContain('The provided credentials are incorrect.');
});
