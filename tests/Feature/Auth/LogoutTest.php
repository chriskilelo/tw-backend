<?php

use App\Models\AuditLog;
use App\Models\User;

it('clears the session on logout (TC-FR-AUTH-010)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/logout')->assertOk();

    $this->assertGuest();
});

it('logs the logout event to audit_logs (TC-FR-AUTH-012-B)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/logout')->assertOk();

    expect(AuditLog::where('user_id', $user->id)->where('action', 'user.logout')->count())->toBe(1);
});

it('rejects logout for an unauthenticated request', function () {
    $this->postJson('/api/v1/logout')->assertUnauthorized();
});
