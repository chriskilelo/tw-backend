<?php

use App\Enums\DirectiveStatus;
use App\Models\Directive;
use App\Models\Notification;
use App\Services\DirectiveService;
use App\Services\SdtService;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Directives\DirectiveWorld;

/**
 * Adversarial coverage for the Directive and Tasking Engine: malformed and
 * hostile query strings, cross-department ids, role changes after issue,
 * stale-model races and date edges that the per-feature suites do not
 * exercise. Every request here must end in a clean 2xx/4xx, never a 500,
 * and never expose another department's data.
 */
beforeEach(function () {
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));
});

it('answers 200 with default handling for array-valued and hostile list query params (TC-FR-DIR-009-X)', function (array $query) {
    $world = DirectiveWorld::create();
    $world->directive();

    $this->actingAs($world->ps)
        ->getJson('/api/v1/directives?'.http_build_query($query))
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
})->with([
    'sort array' => [['sort' => ['created_at']]],
    'status array' => [['status' => ['issued']]],
    'q array' => [['q' => ['x']]],
    'mission_id array' => [['mission_id' => ['x']]],
    'issued_by_user_id array' => [['issued_by_user_id' => ['x']]],
    'date_from array' => [['date_from' => ['2026-01-01']]],
    'due array' => [['due' => ['overdue']]],
    'stale array' => [['stale' => ['1']]],
    'per_page array' => [['per_page' => ['5']]],
    'page array' => [['page' => ['2']]],
    'sort sql injection' => [['sort' => 'created_at; DROP TABLE directives; --']],
    'sort raw column' => [['sort' => 'description']],
    'status unknown' => [['status' => 'exploded']],
    'status draft' => [['status' => 'drafted']],
    'due unknown' => [['due' => 'yesterday']],
    'mission_id not a uuid' => [['mission_id' => "' OR 1=1 --"]],
    'date_from impossible date' => [['date_from' => '2026-02-30']],
]);

it('clamps per_page to 1..100 and falls back to 25 for junk (TC-FR-DIR-009-P2)', function (string $perPage, int $expected) {
    $world = DirectiveWorld::create();
    $world->directive();

    $this->actingAs($world->ps)
        ->getJson("/api/v1/directives?per_page={$perPage}")
        ->assertOk()
        ->assertJsonPath('meta.per_page', $expected);
})->with([
    ['0', 1],
    ['-5', 1],
    ['5000', 100],
    ['abc', 25],
    ['1e9', 100],
]);

it('treats % and _ in q as literals and never matches everything (TC-FR-DIR-009-S2)', function () {
    $world = DirectiveWorld::create();
    $world->directive(['description' => 'Survey the avocado buyers']);
    $world->directive(['description' => 'Raise tariff 5% issue_log']);

    $this->actingAs($world->ps)->getJson('/api/v1/directives?q=%25')->assertOk()->assertJsonPath('meta.total', 1);
    $this->actingAs($world->ps)->getJson('/api/v1/directives?q=_')->assertOk()->assertJsonPath('meta.total', 1);
    $this->actingAs($world->ps)->getJson('/api/v1/directives?q=%5C')->assertOk()->assertJsonPath('meta.total', 0);
});

it('never returns another ministry\'s directives through the list filters (TC-NFR-SEC-006-DIR-F)', function () {
    $world = DirectiveWorld::create();
    $foreign = DirectiveWorld::create();
    $foreign->directive();

    $query = http_build_query([
        'mission_id' => $foreign->mission->id,
        'issued_by_user_id' => $foreign->officer->id,
    ]);

    $this->actingAs($world->ps)->getJson("/api/v1/directives?{$query}")
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

it('returns a zeroed summary and no foreign filter options for another ministry\'s mission and issuer ids (TC-FR-DIR-012-X)', function () {
    $world = DirectiveWorld::create();
    $foreign = DirectiveWorld::create();
    $world->directive();
    $foreign->directive();
    $foreign->directive(['status' => DirectiveStatus::Completed->value]);

    $response = $this->actingAs($world->ps)->getJson('/api/v1/directives/summary?'.http_build_query([
        'mission_id' => $foreign->mission->id,
        'issued_by_user_id' => $foreign->officer->id,
    ]))->assertOk();

    expect($response->json('data.total'))->toBe(0)
        ->and($response->json('data.by_mission'))->toBe([])
        ->and(collect($response->json('data.filter_options.missions'))->pluck('id')->all())->toBe([$world->mission->id])
        ->and(collect($response->json('data.filter_options.issuers'))->pluck('id')->all())->toBe([$world->officer->id]);
});

it('ignores array-valued summary filters instead of failing (TC-FR-DIR-012-X2)', function () {
    $world = DirectiveWorld::create();
    $world->directive();

    $this->actingAs($world->director)
        ->getJson('/api/v1/directives/summary?'.http_build_query(['mission_id' => ['x'], 'date_from' => ['2026-01-01']]))
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.filters.mission_id', null);
});

it('accepts completed (completed or closed) and cancelled as due filters so summary drill-downs match their counts (TC-FR-DIR-012-E)', function () {
    $world = DirectiveWorld::create();
    $completed = $world->directive(['status' => DirectiveStatus::Completed->value]);
    $closed = $world->directive(['status' => DirectiveStatus::Closed->value]);
    $cancelled = $world->directive(['status' => DirectiveStatus::Cancelled->value]);
    $world->directive(['status' => DirectiveStatus::InProgress->value, 'target_completion_date' => now()->subDays(3)->toDateString()]);

    $summary = $this->actingAs($world->ps)->getJson('/api/v1/directives/summary')->assertOk();

    $completedIds = collect($this->actingAs($world->ps)->getJson('/api/v1/directives?due=completed')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    $cancelledIds = collect($this->actingAs($world->ps)->getJson('/api/v1/directives?due=cancelled')->assertOk()->json('data'))->pluck('id')->all();

    expect($completedIds)->toBe(collect([$completed->id, $closed->id])->sort()->values()->all())
        ->and(count($completedIds))->toBe($summary->json('data.completed'))
        ->and($cancelledIds)->toBe([$cancelled->id])
        ->and(count($cancelledIds))->toBe($summary->json('data.cancelled'));
});

it('combines the completed due filter with role narrowing for an attache (TC-FR-DIR-012-E2)', function () {
    $world = DirectiveWorld::create();
    $mine = $world->directive(['status' => DirectiveStatus::Closed->value]);
    $world->directive([
        'status' => DirectiveStatus::Closed->value,
        'mission_id' => $world->otherMission->id,
        'target_user_id' => $world->otherAttache->id,
    ]);

    $this->actingAs($world->attache)->getJson('/api/v1/directives?due=completed')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $mine->id);
});

it('rejects array-valued status and note on a transition with 422, not 500 (TC-FR-DIR-006-X)', function (array $body) {
    $world = DirectiveWorld::create();
    $directive = $world->directive();

    $this->actingAs($world->attache)
        ->patchJson("/api/v1/directives/{$directive->id}/status", $body)
        ->assertUnprocessable();

    expect($directive->fresh()->status)->toBe(DirectiveStatus::Issued);
})->with([
    'status array' => [['status' => ['acknowledged']]],
    'status draft' => [['status' => 'draft']],
    'status issued' => [['status' => 'issued']],
    'note array' => [['status' => 'acknowledged', 'note' => ['x']]],
    'completed with whitespace-only note' => [['status' => 'completed', 'note' => '   ']],
]);

it('refuses to revise with nothing to change, arrays or on a terminal directive (TC-FR-DIR-003-X)', function () {
    $world = DirectiveWorld::create();
    $open = $world->directive();

    $this->actingAs($world->officer)->patchJson("/api/v1/directives/{$open->id}", [])->assertUnprocessable();
    $this->actingAs($world->officer)->patchJson("/api/v1/directives/{$open->id}", ['target_completion_date' => ['2026-12-01']])->assertUnprocessable();
    $this->actingAs($world->officer)->patchJson("/api/v1/directives/{$open->id}", ['type_category' => ['x']])->assertUnprocessable();
    $this->actingAs($world->officer)->patchJson("/api/v1/directives/{$open->id}", ['target_completion_date' => '2026-09-30'])->assertUnprocessable();

    $this->actingAs($world->officer)->patchJson("/api/v1/directives/{$open->id}", ['type_category' => null])->assertOk();
    expect(Notification::query()->where('trigger_type', 'directive_revised')->count())->toBe(0);

    foreach ([DirectiveStatus::Completed, DirectiveStatus::Closed, DirectiveStatus::Cancelled] as $status) {
        $finished = $world->directive(['status' => $status->value]);

        $this->actingAs($world->officer)
            ->patchJson("/api/v1/directives/{$finished->id}", ['target_completion_date' => null])
            ->assertUnprocessable();
    }
});

it('rejects every transition out of a terminal directive for either party (TC-FR-DIR-006-T)', function (string $from) {
    $world = DirectiveWorld::create();
    $directive = $world->directive(['status' => $from]);

    foreach (['acknowledged', 'in_progress', 'completed'] as $status) {
        $this->actingAs($world->attache)
            ->patchJson("/api/v1/directives/{$directive->id}/status", ['status' => $status, 'note' => 'Done.'])
            ->assertUnprocessable();
    }

    foreach (['cancelled', 'closed'] as $status) {
        $this->actingAs($world->officer)
            ->patchJson("/api/v1/directives/{$directive->id}/status", ['status' => $status, 'note' => 'Done.'])
            ->assertUnprocessable();
    }

    expect($directive->fresh()->status->value)->toBe($from);
})->with(['cancelled', 'closed']);

it('re-checks the current status under a row lock so a stale model cannot overwrite a concurrent transition (TC-FR-DIR-006-R)', function () {
    $world = DirectiveWorld::create();
    $directive = $world->directive(['status' => DirectiveStatus::InProgress->value]);
    $staleCopy = Directive::query()->withoutGlobalScopes()->findOrFail($directive->id);

    $this->actingAs($world->officer);
    app(DirectiveService::class)->transitionStatus($directive, 'cancelled', $world->officer, 'Withdrawn: superseded.');

    $this->actingAs($world->attache);
    expect(fn () => app(DirectiveService::class)->transitionStatus($staleCopy, 'completed', $world->attache, 'Delivered.'))
        ->toThrow(InvalidArgumentException::class);

    expect($directive->fresh()->status)->toBe(DirectiveStatus::Cancelled)
        ->and($directive->fresh()->completion_summary)->toBeNull();
});

it('answers 404 for every write on another ministry\'s directive, even to its counterpart roles (TC-NFR-SEC-006-DIR-W)', function () {
    $world = DirectiveWorld::create();
    $foreign = DirectiveWorld::create();
    $directive = $foreign->directive();

    foreach ([$world->attache, $world->officer, $world->ps, $world->director] as $actor) {
        $this->actingAs($actor)->getJson("/api/v1/directives/{$directive->id}")->assertNotFound();
        $this->actingAs($actor)->patchJson("/api/v1/directives/{$directive->id}/status", ['status' => 'acknowledged'])->assertNotFound();
        $this->actingAs($actor)->postJson("/api/v1/directives/{$directive->id}/notes", ['content' => 'x'])->assertNotFound();
        $this->actingAs($actor)->patchJson("/api/v1/directives/{$directive->id}", ['type_category' => 'x'])->assertNotFound();
    }

    $this->actingAs($world->officer)->getJson('/api/v1/directives/not-a-uuid')->assertNotFound();
    expect($directive->fresh()->status)->toBe(DirectiveStatus::Issued)
        ->and($directive->fresh()->notes()->count())->toBe(0);
});

it('refuses non-issuer roles as assignees and never issues to another ministry\'s attache or mission (TC-FR-DIR-002-X)', function () {
    $world = DirectiveWorld::create();
    $foreign = DirectiveWorld::create();
    $hom = $world->member('Head of Mission');

    foreach ([$world->otherOfficer, $world->director, $world->ps, $hom, $foreign->attache] as $target) {
        $this->actingAs($world->officer)
            ->postJson('/api/v1/directives', $world->issueBody(['target_user_id' => $target->id]))
            ->assertUnprocessable();
    }

    $this->actingAs($world->officer)
        ->postJson('/api/v1/directives', $world->issueBody(['mission_id' => $foreign->mission->id]))
        ->assertUnprocessable();

    $this->actingAs($world->officer)
        ->postJson('/api/v1/directives', $world->issueBody(['status' => 'closed', 'ministry_id' => $foreign->ministry->id, 'issued_by_user_id' => $world->ps->id]))
        ->assertCreated()
        ->assertJsonPath('data.status', 'issued')
        ->assertJsonPath('data.issued_by.id', $world->officer->id);

    expect(Directive::query()->withoutGlobalScopes()->where('ministry_id', $foreign->ministry->id)->count())->toBe(0)
        ->and(Notification::query()->where('recipient_user_id', $foreign->attache->id)->count())->toBe(0);
});

it('keeps an issuer\'s withdraw and close rights and grants the summary after a real Acting PS role swap (TC-FR-SDT-004-DIR-B)', function () {
    $world = DirectiveWorld::create();
    DirectiveWorld::role('Acting PS');
    $open = $world->directive();
    $completed = $world->directive(['status' => DirectiveStatus::Completed->value, 'completion_summary' => 'Delivered.']);
    $foreignToOfficer = $world->directive(['issued_by_user_id' => $world->otherOfficer->id]);

    app(SdtService::class)->activateActingPs($world->ps, $world->officer);
    $actingPs = $world->officer->fresh();
    expect($actingPs->role->name)->toBe('Acting PS');

    $this->actingAs($actingPs)->getJson('/api/v1/directives')->assertOk()->assertJsonPath('meta.total', 3);
    $this->actingAs($actingPs)->getJson('/api/v1/directives/summary')->assertOk();
    $this->actingAs($actingPs)->patchJson("/api/v1/directives/{$completed->id}/status", ['status' => 'closed'])->assertOk();
    $this->actingAs($actingPs)->patchJson("/api/v1/directives/{$open->id}/status", ['status' => 'cancelled', 'note' => 'Superseded.'])->assertOk();
    $this->actingAs($actingPs)->patchJson("/api/v1/directives/{$foreignToOfficer->id}/status", ['status' => 'cancelled', 'note' => 'x'])->assertForbidden();
});

it('lets the target attache complete after a deactivated issuer, without failing on the missing recipient (TC-FR-DIR-007-X)', function () {
    $world = DirectiveWorld::create();
    $directive = $world->directive(['status' => DirectiveStatus::InProgress->value]);
    $world->officer->delete();

    $this->actingAs($world->attache)
        ->patchJson("/api/v1/directives/{$directive->id}/status", ['status' => 'completed', 'note' => 'Delivered.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.issued_by.id', $world->officer->id);

    expect(Notification::query()->where('trigger_type', 'directive_status_changed')->count())->toBe(0);
});

it('keeps a target attache posted to another mission of the same ministry on their directive (TC-BR-018-X)', function () {
    $world = DirectiveWorld::create();
    $directive = $world->directive();
    $world->attache->forceFill(['mission_id' => $world->otherMission->id])->save();

    $this->actingAs($world->attache->fresh())
        ->patchJson("/api/v1/directives/{$directive->id}/status", ['status' => 'acknowledged'])
        ->assertOk();

    expect($directive->fresh()->mission_id)->toBe($world->mission->id)
        ->and($directive->fresh()->target_user_id)->toBe($world->attache->id);
});

it('never notifies a former target or issuer who has moved to another ministry (TC-NFR-SEC-006-DIR-N)', function () {
    $world = DirectiveWorld::create();
    $foreign = DirectiveWorld::create();
    $directive = $world->directive(['last_progress_update_at' => now()->subDays(20), 'target_completion_date' => now()->addDays(7)->toDateString()]);
    $world->attache->forceFill(['ministry_id' => $foreign->ministry->id, 'mission_id' => $foreign->mission->id])->save();

    $this->artisan('directive:flag-stale')->assertSuccessful();
    $this->artisan('directive:send-reminders')->assertSuccessful();

    expect(Notification::query()->where('recipient_user_id', $world->attache->id)->count())->toBe(0)
        ->and(Notification::query()->where('recipient_user_id', $world->officer->id)->where('trigger_type', 'directive_stale')->count())->toBe(1);

    $this->actingAs($world->attache->fresh())->getJson("/api/v1/directives/{$directive->id}")->assertNotFound();

    $this->actingAs($world->officer)
        ->postJson("/api/v1/directives/{$directive->id}/notes", ['content' => 'Chasing.'])
        ->assertCreated();

    expect(Notification::query()->where('recipient_user_id', $world->attache->id)->count())->toBe(0);
});

it('is not overdue on its due date, becomes overdue the day after, and counts both correctly in the summary (TC-BR-017-X)', function () {
    $world = DirectiveWorld::create();
    $directive = $world->directive(['target_completion_date' => '2026-10-01']);

    $this->actingAs($world->ps)->getJson("/api/v1/directives/{$directive->id}")
        ->assertJsonPath('data.due_state', 'approaching')
        ->assertJsonPath('data.is_overdue', false)
        ->assertJsonPath('data.days_until_due', 0);
    $this->actingAs($world->ps)->getJson('/api/v1/directives?due=overdue')->assertJsonPath('meta.total', 0);

    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(23, 59, 59));
    $this->actingAs($world->ps)->getJson('/api/v1/directives/summary')->assertJsonPath('data.overdue', 0);

    $this->travelTo(now()->setDate(2026, 10, 2)->setTime(0, 0, 1));
    $this->actingAs($world->ps)->getJson("/api/v1/directives/{$directive->id}")
        ->assertJsonPath('data.due_state', 'overdue')
        ->assertJsonPath('data.days_until_due', -1)
        ->assertJsonPath('data.status', 'issued');
    $this->actingAs($world->ps)->getJson('/api/v1/directives?due=overdue')->assertJsonPath('meta.total', 1);
    $this->actingAs($world->ps)->getJson('/api/v1/directives/summary')->assertJsonPath('data.overdue', 1);
});

it('counts closed as completed but never as overdue, even when its date has passed (TC-FR-DIR-012-F)', function () {
    $world = DirectiveWorld::create();
    $world->directive(['status' => DirectiveStatus::Closed->value, 'target_completion_date' => '2026-09-01']);
    $world->directive(['status' => DirectiveStatus::Cancelled->value, 'target_completion_date' => '2026-09-01']);

    $this->actingAs($world->ps)->getJson('/api/v1/directives/summary')
        ->assertJsonPath('data.completed', 1)
        ->assertJsonPath('data.closed', 1)
        ->assertJsonPath('data.cancelled', 1)
        ->assertJsonPath('data.overdue', 0)
        ->assertJsonPath('data.percentages.completed', 50);
});

it('shows a deactivated attache by name in the PS overview (BR-002, TC-FR-SDT-008-X)', function () {
    $world = DirectiveWorld::create();
    $world->directive();
    $world->attache->delete();

    $this->actingAs($world->ps)->getJson('/api/v1/sdt/directives/overview')
        ->assertOk()
        ->assertJsonPath('data.recent_directives.0.target_user.full_name', 'Amina Attache');
});
