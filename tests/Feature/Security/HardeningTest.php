<?php

use App\Models\Alert;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * NFR-SEC-004 (Session 38): pre-audit hardening — security response
 * headers, rate limiting, and file-upload allowlist enforcement.
 */
function hardeningRole(string $name, string $layer = '2', string $scope = 'mission'): Role
{
    return Role::query()->firstOrCreate(['name' => $name], ['layer' => $layer, 'scope' => $scope]);
}

function hardeningMinistryAttache(Ministry $ministry, Mission $mission): User
{
    return User::factory()->create([
        'role_id' => hardeningRole('Ministry Attache')->id,
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
    ]);
}

it('includes X-Frame-Options: SAMEORIGIN on every authenticated response (TC-NFR-SEC-001)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $user = hardeningMinistryAttache($ministry, $mission);

    $response = $this->actingAs($user)->getJson('/api/v1/me');

    $response->assertOk();
    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('includes the same security headers on an unauthenticated response', function () {
    $response = $this->postJson('/api/v1/login', []);

    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

it('returns 429 on the 6th login attempt within a minute (TC-NFR-SEC-002)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $user = hardeningMinistryAttache($ministry, $mission);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
    }

    $sixthResponse = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $sixthResponse->assertStatus(429);
});

it('returns 422 for a file upload with a .php extension (TC-NFR-SEC-003)', function () {
    Storage::fake('uploads');

    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = hardeningMinistryAttache($ministry, $mission);
    $alert = Alert::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'submitted_by_user_id' => $attache->id,
    ]);

    $maliciousFile = UploadedFile::fake()->create('malicious.php', 10, 'application/x-php');

    $response = $this->actingAs($attache)->postJson(
        "/api/v1/alerts/{$alert->id}/attachments",
        ['file' => $maliciousFile],
    );

    $response->assertStatus(422);
    Storage::disk('uploads')->assertDirectoryEmpty("alerts/{$alert->id}");
});

it('accepts an allowlisted .pdf attachment and stores it under a UUID filename, not the original name', function () {
    Storage::fake('uploads');

    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = hardeningMinistryAttache($ministry, $mission);
    $alert = Alert::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'submitted_by_user_id' => $attache->id,
    ]);

    $file = UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf');

    $response = $this->actingAs($attache)->postJson(
        "/api/v1/alerts/{$alert->id}/attachments",
        ['file' => $file],
    );

    $response->assertCreated();

    $storedPath = $alert->attachments()->first()->file_path;
    expect($storedPath)->not->toContain('evidence.pdf');
    expect(basename($storedPath))->toMatch('/^[0-9a-f-]{36}\.pdf$/');
    Storage::disk('uploads')->assertExists($storedPath);
});
