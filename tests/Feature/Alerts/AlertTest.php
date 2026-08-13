<?php

use App\Enums\AlertStatus;
use App\Jobs\SendAlertRoutingNotification;
use App\Models\Alert;
use App\Models\AlertVersion;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * CLAUDE.md Section 11, 14 / API-001 Section 6 (Intelligence Alert Engine).
 */
function alertRole(string $name, string $layer = '2', string $scope = 'mission'): Role
{
    return Role::query()->firstOrCreate(['name' => $name], ['layer' => $layer, 'scope' => $scope]);
}

function alertMinistryAttache(Ministry $ministry, Mission $mission): User
{
    return User::factory()->create([
        'role_id' => alertRole('Ministry Attache')->id,
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
    ]);
}

function alertMinistryPs(Ministry $ministry): User
{
    return User::factory()->create([
        'role_id' => alertRole('Ministry PS', '2/3', 'ministry')->id,
        'ministry_id' => $ministry->id,
    ]);
}

it('lets a Ministry Attache submit an alert with a generated reference number and status new (TC-FR-ALERT-002)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = alertMinistryAttache($ministry, $mission);

    $response = $this->actingAs($attache)->postJson('/api/v1/alerts', [
        'country' => 'United Kingdom',
        'intelligence_type' => 'opportunities',
        'sector' => 'Agriculture',
    ]);

    $response->assertCreated();
    expect($response->json('data.reference_number'))->toStartWith('ALT-'.now()->format('Ym').'-');
    expect($response->json('data.status'))->toBe(AlertStatus::New->value);

    $this->assertDatabaseHas('alerts', [
        'submitted_by_user_id' => $attache->id,
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'status' => AlertStatus::New->value,
    ]);
});

it('rejects submission when a mandatory field is missing', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = alertMinistryAttache($ministry, $mission);

    $this->actingAs($attache)
        ->postJson('/api/v1/alerts', ['intelligence_type' => 'opportunities'])
        ->assertStatus(422);
});

it('makes a submitted alert appear on the configured recipient endpoint (TC-FR-ALERT-005)', function () {
    Queue::fake();

    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = alertMinistryAttache($ministry, $mission);
    $ps = alertMinistryPs($ministry);

    $this->actingAs($attache)->postJson('/api/v1/alerts', [
        'country' => 'Germany',
        'intelligence_type' => 'trade_barriers',
    ])->assertCreated();

    $response = $this->actingAs($ps)->getJson('/api/v1/alerts');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.status'))->toBe(AlertStatus::New->value);

    Queue::assertPushed(SendAlertRoutingNotification::class, fn ($job) => $job->email === $ps->email);
});

it('lets a Ministry PS delegate an alert, setting it to assigned and notifying the delegate (TC-FR-ALERT-007)', function () {
    Queue::fake();

    $ministry = Ministry::factory()->create();
    $ps = alertMinistryPs($ministry);
    $delegate = User::factory()->create([
        'role_id' => alertRole('Ministry HQ Officer')->id,
        'ministry_id' => $ministry->id,
    ]);
    $alert = Alert::factory()->create(['ministry_id' => $ministry->id, 'status' => AlertStatus::New]);

    $response = $this->actingAs($ps)->postJson("/api/v1/alerts/{$alert->id}/delegate", [
        'delegate_user_ids' => [$delegate->id],
    ]);

    $response->assertOk();
    expect($response->json('data.status'))->toBe(AlertStatus::Assigned->value);

    $this->assertDatabaseHas('alerts', [
        'id' => $alert->id,
        'status' => AlertStatus::Assigned->value,
        'assigned_to_user_id' => $delegate->id,
    ]);

    $this->assertDatabaseHas('notifications', [
        'recipient_user_id' => $delegate->id,
        'trigger_type' => 'alert_routed',
    ]);

    Queue::assertPushed(SendAlertRoutingNotification::class, fn ($job) => $job->email === $delegate->email);
});

it('rejects a non-PS role from delegating an alert', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = alertMinistryAttache($ministry, $mission);
    $delegate = User::factory()->create(['ministry_id' => $ministry->id]);
    $alert = Alert::factory()->create(['ministry_id' => $ministry->id]);

    $this->actingAs($attache)
        ->postJson("/api/v1/alerts/{$alert->id}/delegate", ['delegate_user_ids' => [$delegate->id]])
        ->assertForbidden();
});

it('lets the assigned delegate acknowledge an alert (TC-FR-ALERT-009)', function () {
    $ministry = Ministry::factory()->create();
    $delegate = User::factory()->create([
        'role_id' => alertRole('Ministry HQ Officer')->id,
        'ministry_id' => $ministry->id,
    ]);
    $alert = Alert::factory()->create([
        'ministry_id' => $ministry->id,
        'status' => AlertStatus::Assigned,
        'assigned_to_user_id' => $delegate->id,
    ]);

    $response = $this->actingAs($delegate)->postJson("/api/v1/alerts/{$alert->id}/acknowledge");

    $response->assertOk();
    expect($response->json('data.status'))->toBe(AlertStatus::Acknowledged->value);

    $this->assertDatabaseHas('alerts', [
        'id' => $alert->id,
        'status' => AlertStatus::Acknowledged->value,
    ]);
});

it('creates an alert_versions snapshot when the submitting attache edits an alert (TC-FR-ALERT-011)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = alertMinistryAttache($ministry, $mission);
    $alert = Alert::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'submitted_by_user_id' => $attache->id,
        'sector' => 'Original Sector',
    ]);

    $response = $this->actingAs($attache)->patchJson("/api/v1/alerts/{$alert->id}", [
        'sector' => 'Updated Sector',
    ]);

    $response->assertOk();
    expect($response->json('data.sector'))->toBe('Updated Sector');

    $this->assertDatabaseHas('alert_versions', [
        'alert_id' => $alert->id,
        'edited_by_user_id' => $attache->id,
    ]);

    $version = AlertVersion::where('alert_id', $alert->id)->firstOrFail();
    expect($version->snapshot['sector'])->toBe('Original Sector');
});

it('rejects a delete attempt on a submitted alert with 405 (TC-FR-ALERT-013)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = alertMinistryAttache($ministry, $mission);
    $alert = Alert::factory()->create([
        'ministry_id' => $ministry->id,
        'submitted_by_user_id' => $attache->id,
    ]);

    $this->actingAs($attache)
        ->deleteJson("/api/v1/alerts/{$alert->id}")
        ->assertStatus(405);
});

it('never returns another ministry\'s alerts through the real alerts endpoint (TC-NFR-SEC-006-A)', function () {
    $ministryA = Ministry::factory()->create();
    $ministryB = Ministry::factory()->create();
    $missionA = Mission::factory()->create();
    $missionB = Mission::factory()->create();

    $attacheA = alertMinistryAttache($ministryA, $missionA);
    alertMinistryAttache($ministryB, $missionB);

    Alert::factory()->create(['ministry_id' => $ministryA->id]);
    $foreignAlert = Alert::factory()->create(['ministry_id' => $ministryB->id]);

    $response = $this->actingAs($attacheA)->getJson('/api/v1/alerts');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect(collect($response->json('data'))->pluck('id'))->not->toContain($foreignAlert->id);

    $this->actingAs($attacheA)
        ->getJson("/api/v1/alerts/{$foreignAlert->id}")
        ->assertNotFound();
});

it('responds within 1000ms for the alerts list with 100 seeded alerts (TC-NFR-PERF-001)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $ps = alertMinistryPs($ministry);

    Alert::factory()->count(100)->create(['ministry_id' => $ministry->id, 'mission_id' => $mission->id]);

    $start = microtime(true);

    $response = $this->actingAs($ps)->getJson('/api/v1/alerts');

    $elapsedMs = (microtime(true) - $start) * 1000;

    $response->assertOk();
    expect($elapsedMs)->toBeLessThan(1000);
});

it('rejects an unauthenticated request with 401, never data (TC-COMM-002)', function () {
    Alert::factory()->create();

    $response = $this->getJson('/api/v1/alerts');

    $response->assertStatus(401);
    expect($response->json('data'))->toBeNull();
});
