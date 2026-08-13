<?php

use App\Enums\UserStatus;
use App\Jobs\SendPasswordResetEmail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;

it('dispatches the password reset email job when a reset is requested (TC-FR-AUTH-011)', function () {
    Queue::fake();

    $user = User::factory()->create(['email' => 'attache@example.test']);

    $this->postJson('/api/v1/password/forgot', ['email' => $user->email])
        ->assertOk();

    Queue::assertPushed(SendPasswordResetEmail::class, fn ($job) => $job->email === $user->email);
});

it('returns the same generic response for an unregistered email (TC-FR-AUTH-011-B)', function () {
    Queue::fake();

    $this->postJson('/api/v1/password/forgot', ['email' => 'nobody@example.test'])
        ->assertOk();

    Queue::assertNotPushed(SendPasswordResetEmail::class);
});

it('resets the password, reactivates a locked account, and invalidates old sessions (TC-FR-AUTH-011-C)', function () {
    $user = User::factory()->create([
        'email' => 'attache@example.test',
        'password' => Hash::make('OldPassword1'),
        'status' => UserStatus::Locked,
        'failed_login_attempts' => 5,
    ]);

    $token = Password::broker('users')->createToken($user);

    $response = $this->postJson('/api/v1/password/reset', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewPassword1',
        'password_confirmation' => 'NewPassword1',
    ]);

    $response->assertOk();

    $user->refresh();

    expect($user->status)->toBe(UserStatus::Active);
    expect($user->failed_login_attempts)->toBe(0);
    expect(Hash::check('NewPassword1', $user->password))->toBeTrue();
});

it('logs a password reset completion to audit_logs (TC-FR-AUTH-012-C)', function () {
    $user = User::factory()->create(['email' => 'attache@example.test']);
    $token = Password::broker('users')->createToken($user);

    $this->postJson('/api/v1/password/reset', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewPassword1',
        'password_confirmation' => 'NewPassword1',
    ])->assertOk();

    expect(AuditLog::where('user_id', $user->id)->where('action', 'user.password_reset.completed')->count())->toBe(1);
});

it('rejects a plain-text password that fails the minimum length/complexity policy (TC-NFR-SEC-003)', function () {
    $user = User::factory()->create(['email' => 'attache@example.test']);
    $token = Password::broker('users')->createToken($user);

    $response = $this->postJson('/api/v1/password/reset', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'weak',
        'password_confirmation' => 'weak',
    ]);

    $response->assertStatus(422);
    expect(collect($response->json('errors'))->contains(fn ($message) => str_contains($message, 'password')))->toBeTrue();

    // Password must be untouched and remain bcrypt-hashed, not overwritten
    // with the rejected plain-text value (NFR-SEC-003).
    $user->refresh();
    expect(Hash::check('weak', $user->password))->toBeFalse();
});

it('stores the password as a bcrypt hash, never plaintext (TC-NFR-SEC-003-B)', function () {
    $user = User::factory()->create(['email' => 'attache@example.test']);
    $token = Password::broker('users')->createToken($user);

    $this->postJson('/api/v1/password/reset', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewPassword1',
        'password_confirmation' => 'NewPassword1',
    ])->assertOk();

    $user->refresh();

    expect($user->password)->not->toBe('NewPassword1');
    expect($user->password)->toStartWith('$2y$');
    expect(Hash::check('NewPassword1', $user->password))->toBeTrue();
});

it('rejects a reset with an invalid token', function () {
    $user = User::factory()->create(['email' => 'attache@example.test']);

    $this->postJson('/api/v1/password/reset', [
        'token' => 'not-a-real-token',
        'email' => $user->email,
        'password' => 'NewPassword1',
        'password_confirmation' => 'NewPassword1',
    ])->assertStatus(422);
});
