<?php

use App\Enums\DirectiveStatus;
use App\Jobs\SendDirectiveIssuedNotification;
use App\Jobs\SendDirectiveStaleReminder;
use App\Models\Directive;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * CLAUDE.md Section 11, 14 / API-001 Section 8 (Directive and Tasking
 * Engine).
 */
function directiveRole(string $name, string $layer = '2', string $scope = 'mission'): Role
{
    return Role::query()->firstOrCreate(['name' => $name], ['layer' => $layer, 'scope' => $scope]);
}

function directiveMinistryAttache(Ministry $ministry, Mission $mission): User
{
    return User::factory()->create([
        'role_id' => directiveRole('Ministry Attache')->id,
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
    ]);
}

function directiveHqOfficer(Ministry $ministry): User
{
    return User::factory()->create([
        'role_id' => directiveRole('Ministry HQ Officer')->id,
        'ministry_id' => $ministry->id,
    ]);
}

function directiveMinistryPs(Ministry $ministry): User
{
    return User::factory()->create([
        'role_id' => directiveRole('Ministry PS', '2/3', 'ministry')->id,
        'ministry_id' => $ministry->id,
    ]);
}

function directiveMinistryHqDirector(Ministry $ministry): User
{
    return User::factory()->create([
        'role_id' => directiveRole('Ministry HQ Director', '2/3', 'ministry')->id,
        'ministry_id' => $ministry->id,
    ]);
}

it('lets a Ministry HQ Officer issue a directive and notifies the target attache (TC-FR-DIR-002)', function () {
    Queue::fake();

    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $officer = directiveHqOfficer($ministry);
    $attache = directiveMinistryAttache($ministry, $mission);

    $response = $this->actingAs($officer)->postJson('/api/v1/directives', [
        'mission_id' => $mission->id,
        'target_user_id' => $attache->id,
        'description' => 'Submit the Q1 asset register update.',
    ]);

    $response->assertCreated();
    expect($response->json('data.status'))->toBe(DirectiveStatus::Issued->value);

    $this->assertDatabaseHas('directives', [
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'target_user_id' => $attache->id,
        'issued_by_user_id' => $officer->id,
        'status' => DirectiveStatus::Issued->value,
    ]);

    $this->assertDatabaseHas('notifications', [
        'recipient_user_id' => $attache->id,
        'trigger_type' => 'directive_issued',
    ]);

    Queue::assertPushed(SendDirectiveIssuedNotification::class, fn ($job) => $job->email === $attache->email);
});

it('rejects transitioning a directive to completed without a completion summary (TC-FR-DIR-007)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $officer = directiveHqOfficer($ministry);
    $attache = directiveMinistryAttache($ministry, $mission);
    $directive = Directive::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'target_user_id' => $attache->id,
        'issued_by_user_id' => $officer->id,
        'status' => DirectiveStatus::InProgress,
        'last_progress_update_at' => now(),
    ]);

    $this->actingAs($attache)
        ->patchJson("/api/v1/directives/{$directive->id}/status", ['status' => DirectiveStatus::Completed->value])
        ->assertStatus(422);

    $this->assertDatabaseHas('directives', [
        'id' => $directive->id,
        'status' => DirectiveStatus::InProgress->value,
        'completion_summary' => null,
    ]);
});

it('completes a directive with a completion summary', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $officer = directiveHqOfficer($ministry);
    $attache = directiveMinistryAttache($ministry, $mission);
    $directive = Directive::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'target_user_id' => $attache->id,
        'issued_by_user_id' => $officer->id,
        'status' => DirectiveStatus::InProgress,
        'last_progress_update_at' => now(),
    ]);

    $response = $this->actingAs($attache)->patchJson("/api/v1/directives/{$directive->id}/status", [
        'status' => DirectiveStatus::Completed->value,
        'note' => 'Asset register submitted and verified.',
    ]);

    $response->assertOk();
    expect($response->json('data.status'))->toBe(DirectiveStatus::Completed->value);

    $this->assertDatabaseHas('directives', [
        'id' => $directive->id,
        'status' => DirectiveStatus::Completed->value,
        'completion_summary' => 'Asset register submitted and verified.',
    ]);

    $this->assertDatabaseHas('directive_notes', [
        'directive_id' => $directive->id,
        'authored_by_user_id' => $attache->id,
        'content' => 'Asset register submitted and verified.',
    ]);
});

it('dispatches a stale reminder for a directive with no progress update in 15 days (TC-FR-DIR-010)', function () {
    Queue::fake();

    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $officer = directiveHqOfficer($ministry);
    $attache = directiveMinistryAttache($ministry, $mission);
    $staleDirective = Directive::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'target_user_id' => $attache->id,
        'issued_by_user_id' => $officer->id,
        'status' => DirectiveStatus::InProgress,
        'last_progress_update_at' => now()->subDays(15),
    ]);
    $freshDirective = Directive::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'target_user_id' => $attache->id,
        'issued_by_user_id' => $officer->id,
        'status' => DirectiveStatus::InProgress,
        'last_progress_update_at' => now()->subDays(2),
    ]);

    $this->artisan('directive:flag-stale')->assertSuccessful();

    $this->assertDatabaseHas('notifications', [
        'recipient_user_id' => $attache->id,
        'trigger_type' => 'directive_stale',
    ]);

    Queue::assertPushed(SendDirectiveStaleReminder::class, fn ($job) => $job->email === $attache->email);
    Queue::assertPushed(SendDirectiveStaleReminder::class, 1);

    expect($freshDirective->fresh())->not->toBeNull();
});

it('rejects a PATCH to /status attempting to change target_user_id (TC-FR-DIR-013, BR-018)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $officer = directiveHqOfficer($ministry);
    $attache = directiveMinistryAttache($ministry, $mission);
    $otherAttache = directiveMinistryAttache($ministry, $mission);
    $directive = Directive::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'target_user_id' => $attache->id,
        'issued_by_user_id' => $officer->id,
        'status' => DirectiveStatus::Issued,
        'last_progress_update_at' => now(),
    ]);

    $this->actingAs($attache)
        ->patchJson("/api/v1/directives/{$directive->id}/status", [
            'status' => DirectiveStatus::Acknowledged->value,
            'target_user_id' => $otherAttache->id,
        ])
        ->assertStatus(422);

    $this->assertDatabaseHas('directives', [
        'id' => $directive->id,
        'target_user_id' => $attache->id,
        'status' => DirectiveStatus::Issued->value,
    ]);
});

it('returns completed/in-progress/overdue counts from the summary endpoint (TC-FR-DIR-012)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $officer = directiveHqOfficer($ministry);
    $ps = directiveMinistryPs($ministry);
    $attache = directiveMinistryAttache($ministry, $mission);

    Directive::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'target_user_id' => $attache->id,
        'issued_by_user_id' => $officer->id,
        'status' => DirectiveStatus::Completed,
        'target_completion_date' => now()->subDays(5),
    ]);
    Directive::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'target_user_id' => $attache->id,
        'issued_by_user_id' => $officer->id,
        'status' => DirectiveStatus::InProgress,
        'target_completion_date' => now()->addDays(5),
    ]);
    Directive::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'target_user_id' => $attache->id,
        'issued_by_user_id' => $officer->id,
        'status' => DirectiveStatus::InProgress,
        'target_completion_date' => now()->subDays(3),
    ]);

    $response = $this->actingAs($ps)->getJson('/api/v1/directives/summary');

    $response->assertOk();
    expect($response->json('data.total'))->toBe(3)
        ->and($response->json('data.completed'))->toBe(1)
        ->and($response->json('data.in_progress'))->toBe(2)
        ->and($response->json('data.overdue'))->toBe(1);
});

it('rejects a Ministry Attache from the summary endpoint', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = directiveMinistryAttache($ministry, $mission);

    $this->actingAs($attache)
        ->getJson('/api/v1/directives/summary')
        ->assertForbidden();
});

it('scopes the directives list to the target for a Ministry Attache, and to all ministry directives for an HQ Director (TC-FR-DIR-005)', function () {
    $ministry = Ministry::factory()->create();
    $missionA = Mission::factory()->create();
    $missionB = Mission::factory()->create();
    $officer = directiveHqOfficer($ministry);
    $attacheA = directiveMinistryAttache($ministry, $missionA);
    $attacheB = directiveMinistryAttache($ministry, $missionB);
    $director = directiveMinistryHqDirector($ministry);

    $directiveForA = Directive::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $missionA->id,
        'target_user_id' => $attacheA->id,
        'issued_by_user_id' => $officer->id,
    ]);

    $directiveForB = Directive::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $missionB->id,
        'target_user_id' => $attacheB->id,
        'issued_by_user_id' => $officer->id,
    ]);

    $attacheAIds = collect(
        $this->actingAs($attacheA)->getJson('/api/v1/directives')->assertOk()->json('data')
    )->pluck('id');

    expect($attacheAIds)->toContain($directiveForA->id)
        ->and($attacheAIds)->not->toContain($directiveForB->id);

    $directorIds = collect(
        $this->actingAs($director)->getJson('/api/v1/directives')->assertOk()->json('data')
    )->pluck('id');

    expect($directorIds)->toContain($directiveForA->id)
        ->and($directorIds)->toContain($directiveForB->id);
});
