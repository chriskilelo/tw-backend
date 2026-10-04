<?php

use App\Models\DirectiveNote;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Directives\DirectiveWorld;

/**
 * URD Section 10.5 lifecycle diagram, FR-DIR-006, 007, 008, 011, 013, 014:
 * the state machine, who drives each step, required notes, the progress
 * clock, notifications to the counterpart, revisions and the detail
 * resource's status_history / notes / allowed_actions.
 */
beforeEach(function () {
    Queue::fake();
});

/**
 * @return array<string, array{0: string, 1: string, 2: string, 3: string|null}>
 */
function directiveLegalTransitions(): array
{
    return [
        'issued -> acknowledged' => ['issued', 'acknowledged', 'target', null],
        'issued -> in_progress (FR-DIR-006 AC1)' => ['issued', 'in_progress', 'target', null],
        'acknowledged -> in_progress' => ['acknowledged', 'in_progress', 'target', null],
        'in_progress -> completed' => ['in_progress', 'completed', 'target', 'Shared 14 verified buyer contacts with HQ.'],
        'issued -> cancelled' => ['issued', 'cancelled', 'issuer', 'The fair was postponed.'],
        'acknowledged -> cancelled' => ['acknowledged', 'cancelled', 'issuer', 'Superseded.'],
        'in_progress -> cancelled' => ['in_progress', 'cancelled', 'issuer', 'No longer needed.'],
        'completed -> closed' => ['completed', 'closed', 'issuer', null],
    ];
}

function directiveActor(DirectiveWorld $world, string $side): User
{
    return $side === 'target' ? $world->attache : $world->officer;
}

it('allows every legal transition for the right actor and notifies the counterpart (TC-FR-DIR-006, TC-FR-DIR-014)', function (string $from, string $to, string $side, ?string $note) {
    $world = DirectiveWorld::create();
    $directive = $world->directive(['status' => $from, 'last_progress_update_at' => now()->subDays(5)]);
    $actor = directiveActor($world, $side);
    $counterpart = $side === 'target' ? $world->officer : $world->attache;

    $response = $this->actingAs($actor)->patchJson("/api/v1/directives/{$directive->id}/status", array_filter(['status' => $to, 'note' => $note]));

    $response->assertOk()->assertJsonPath('data.status', $to);

    expect($directive->fresh()->last_progress_update_at->isToday())->toBeTrue();

    $notifications = Notification::query()->where('trigger_type', 'directive_status_changed')->get();
    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->recipient_user_id)->toBe($counterpart->id)
        ->and($notifications->first()->link)->toBe("/directives/{$directive->id}")
        ->and($notifications->first()->message)->toContain($actor->full_name);
})->with(directiveLegalTransitions());

it('rejects every illegal transition with 422, even for the actor who drives that step (TC-FR-DIR-006-B)', function (string $from, string $to) {
    $world = DirectiveWorld::create();
    $directive = $world->directive(['status' => $from]);
    $side = in_array($to, ['cancelled', 'closed'], true) ? 'issuer' : 'target';

    $this->actingAs(directiveActor($world, $side))
        ->patchJson("/api/v1/directives/{$directive->id}/status", ['status' => $to, 'note' => 'A note to satisfy validation.'])
        ->assertUnprocessable();

    expect($directive->fresh()->status->value)->toBe($from)
        ->and(Notification::query()->count())->toBe(0)
        ->and($directive->notes()->count())->toBe(0);
})->with(function (): array {
    $legal = collect(directiveLegalTransitions())->map(fn (array $row): string => "{$row[0]}>{$row[1]}")->all();
    $cases = [];

    foreach (['issued', 'acknowledged', 'in_progress', 'completed', 'cancelled', 'closed'] as $from) {
        foreach (['acknowledged', 'in_progress', 'completed', 'cancelled', 'closed'] as $to) {
            if (! in_array("{$from}>{$to}", $legal, true)) {
                $cases["{$from} -> {$to}"] = [$from, $to];
            }
        }
    }

    return $cases;
});

it('refuses the wrong actor for each step (TC-FR-DIR-006-C, TC-FR-DIR-007-C)', function (string $from, string $to, string $side) {
    $world = DirectiveWorld::create();
    $directive = $world->directive(['status' => $from]);

    $this->actingAs(directiveActor($world, $side))
        ->patchJson("/api/v1/directives/{$directive->id}/status", ['status' => $to, 'note' => 'Attempt.'])
        ->assertForbidden();

    expect($directive->fresh()->status->value)->toBe($from);
})->with([
    'issuer acknowledging' => ['issued', 'acknowledged', 'issuer'],
    'issuer starting work' => ['acknowledged', 'in_progress', 'issuer'],
    'issuer completing' => ['in_progress', 'completed', 'issuer'],
    'target withdrawing' => ['issued', 'cancelled', 'target'],
    'target closing' => ['completed', 'closed', 'target'],
]);

it('refuses a PS who did not issue the directive to withdraw or close it (TC-FR-DIR-006-D)', function () {
    $world = DirectiveWorld::create();
    $open = $world->directive();
    $completed = $world->directive(['status' => 'completed']);

    $this->actingAs($world->ps)->patchJson("/api/v1/directives/{$open->id}/status", ['status' => 'cancelled', 'note' => 'x'])->assertForbidden();
    $this->actingAs($world->ps)->patchJson("/api/v1/directives/{$completed->id}/status", ['status' => 'closed'])->assertForbidden();
});

it('validates the status request (TC-FR-DIR-006-V)', function (array $body, string $message) {
    $world = DirectiveWorld::create();
    $directive = $world->directive(['status' => 'in_progress']);
    $actor = ($body['status'] ?? null) === 'cancelled' ? $world->officer : $world->attache;

    $response = $this->actingAs($actor)->patchJson("/api/v1/directives/{$directive->id}/status", $body);

    $response->assertUnprocessable();
    expect(implode(' ', $response->json('errors')))->toContain($message)
        ->and($directive->fresh()->status->value)->toBe('in_progress');
})->with([
    'missing status' => [[], 'status'],
    'draft is not a transition target' => [['status' => 'draft'], 'not a valid directive status change'],
    'issued is not a transition target' => [['status' => 'issued'], 'not a valid directive status change'],
    'unknown status' => [['status' => 'finished'], 'not a valid directive status change'],
    'completion without summary (FR-DIR-007)' => [['status' => 'completed'], 'completion summary is required'],
    'completion with a blank summary' => [['status' => 'completed', 'note' => '    '], 'completion summary is required'],
    'withdrawal without a reason' => [['status' => 'cancelled'], 'reason for withdrawing'],
    'note too long' => [['status' => 'completed', 'note' => str_repeat('a', 5001)], '5000 characters'],
    'retargeting the attache (BR-018)' => [['status' => 'completed', 'note' => 'Done', 'target_user_id' => '0b5a51c5-7f6e-4a51-9c7c-1c2f1e5b0d11'], 'target attache of an issued directive cannot be changed'],
    'retargeting the mission (BR-018)' => [['status' => 'completed', 'note' => 'Done', 'mission_id' => '0b5a51c5-7f6e-4a51-9c7c-1c2f1e5b0d11'], 'target mission of an issued directive cannot be changed'],
]);

it('stores the completion summary as the directive summary and as a note, and quotes it to the issuer (TC-FR-DIR-007-A, TC-FR-DIR-007-B)', function () {
    $world = DirectiveWorld::create();
    $directive = $world->directive(['status' => 'in_progress']);

    $this->actingAs($world->attache)
        ->patchJson("/api/v1/directives/{$directive->id}/status", ['status' => 'completed', 'note' => '  Shared 14 buyer contacts.  '])
        ->assertOk()
        ->assertJsonPath('data.completion_summary', 'Shared 14 buyer contacts.')
        ->assertJsonPath('data.due_state', 'completed');

    expect($directive->notes()->pluck('content')->all())->toBe(['Shared 14 buyer contacts.']);

    $notification = Notification::query()->where('recipient_user_id', $world->officer->id)->sole();
    expect($notification->trigger_type)->toBe('directive_status_changed')
        ->and($notification->message)->toContain('marked Completed by Amina Attache')
        ->and($notification->message)->toContain('Summary: "Shared 14 buyer contacts."');
});

it('quotes the withdrawal reason to the target attache when the issuer cancels', function () {
    $world = DirectiveWorld::create();
    $directive = $world->directive();

    $this->actingAs($world->officer)
        ->patchJson("/api/v1/directives/{$directive->id}/status", ['status' => 'cancelled', 'note' => 'The fair was postponed.'])
        ->assertOk();

    $notification = Notification::query()->where('recipient_user_id', $world->attache->id)->sole();
    expect($notification->message)->toContain('marked Cancelled by Carol Officer')
        ->and($notification->message)->toContain('Reason: "The fair was postponed."');
});

it('writes a note only when one is given, never an automatic status note (TC-FR-DIR-006-N)', function () {
    $world = DirectiveWorld::create();
    $directive = $world->directive();

    $this->actingAs($world->attache)->patchJson("/api/v1/directives/{$directive->id}/status", ['status' => 'acknowledged'])->assertOk();
    expect($directive->notes()->count())->toBe(0);

    $this->actingAs($world->attache)->patchJson("/api/v1/directives/{$directive->id}/status", ['status' => 'in_progress', 'note' => 'Started calls.'])->assertOk();
    expect($directive->notes()->pluck('content')->all())->toBe(['Started calls.']);
});

it('treats a target note as progress: bumps the clock and notifies the issuer (TC-FR-DIR-008)', function () {
    $world = DirectiveWorld::create();
    $directive = $world->directive(['status' => 'in_progress', 'last_progress_update_at' => now()->subDays(20)]);

    $this->actingAs($world->attache)
        ->postJson("/api/v1/directives/{$directive->id}/notes", ['content' => 'Met three importers.'])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'progress')
        ->assertJsonPath('data.content', 'Met three importers.')
        ->assertJsonPath('data.authored_by.full_name', 'Amina Attache');

    expect($directive->fresh()->last_progress_update_at->isToday())->toBeTrue()
        ->and($directive->fresh()->isStale())->toBeFalse();

    $notification = Notification::query()->sole();
    expect($notification->recipient_user_id)->toBe($world->officer->id)
        ->and($notification->trigger_type)->toBe('directive_note_added')
        ->and($notification->link)->toBe("/directives/{$directive->id}");
});

it('treats an issuer note as a follow-up: leaves the clock and notifies the target (TC-FR-DIR-011)', function () {
    $world = DirectiveWorld::create();
    $staleSince = now()->subDays(20)->startOfSecond();
    $directive = $world->directive(['status' => 'in_progress', 'last_progress_update_at' => $staleSince]);

    $this->actingAs($world->officer)
        ->postJson("/api/v1/directives/{$directive->id}/notes", ['content' => 'Any update on this?'])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'follow_up');

    expect($directive->fresh()->last_progress_update_at->equalTo($staleSince))->toBeTrue()
        ->and($directive->fresh()->isStale())->toBeTrue();

    $notification = Notification::query()->sole();
    expect($notification->recipient_user_id)->toBe($world->attache->id)
        ->and($notification->trigger_type)->toBe('directive_follow_up');
});

it('accepts notes on a finished directive and validates note content (TC-FR-DIR-008-V)', function () {
    $world = DirectiveWorld::create();
    $closed = $world->directive(['status' => 'closed']);

    $this->actingAs($world->officer)->postJson("/api/v1/directives/{$closed->id}/notes", ['content' => 'Thanks, filed.'])->assertCreated();
    $this->actingAs($world->attache)->postJson("/api/v1/directives/{$closed->id}/notes", [])->assertUnprocessable();
    $this->actingAs($world->attache)->postJson("/api/v1/directives/{$closed->id}/notes", ['content' => '   '])->assertUnprocessable();
    $this->actingAs($world->attache)->postJson("/api/v1/directives/{$closed->id}/notes", ['content' => str_repeat('a', 5001)])->assertUnprocessable();

    expect($closed->notes()->count())->toBe(1);
});

it('lets the issuer revise the target date, recording a note and notifying the target (TC-FR-DIR-003-R)', function () {
    $this->travelTo(now()->setDate(2026, 10, 1)->startOfDay());

    $world = DirectiveWorld::create();
    $progressAt = now()->subDays(3)->startOfSecond();
    $directive = $world->directive(['target_completion_date' => '2026-10-12', 'last_progress_update_at' => $progressAt]);

    $this->actingAs($world->officer)
        ->patchJson("/api/v1/directives/{$directive->id}", ['target_completion_date' => '2026-10-20'])
        ->assertOk()
        ->assertJsonPath('data.due_state', 'on_track')
        ->assertJsonPath('data.days_until_due', 19);

    expect($directive->fresh()->target_completion_date->toDateString())->toBe('2026-10-20')
        ->and($directive->fresh()->last_progress_update_at->equalTo($progressAt))->toBeTrue()
        ->and($directive->notes()->sole()->content)->toBe('Target completion date changed from 12 Oct 2026 to 20 Oct 2026.');

    $notification = Notification::query()->sole();
    expect($notification->recipient_user_id)->toBe($world->attache->id)
        ->and($notification->trigger_type)->toBe('directive_revised')
        ->and($notification->message)->toContain('Target completion date changed from 12 Oct 2026 to 20 Oct 2026.');

    $this->actingAs($world->officer)
        ->patchJson("/api/v1/directives/{$directive->id}", ['target_completion_date' => null])
        ->assertOk()
        ->assertJsonPath('data.due_state', 'no_date')
        ->assertJsonPath('data.target_completion_date', null);

    expect(DirectiveNote::query()->latest('created_at')->orderByDesc('id')->pluck('content'))->toContain('Target completion date changed from 20 Oct 2026 to no date set.');
});

it('revises the directive type and ignores a revision that changes nothing', function () {
    $world = DirectiveWorld::create();
    $directive = $world->directive(['type_category' => 'Market research']);

    $this->actingAs($world->officer)
        ->patchJson("/api/v1/directives/{$directive->id}", ['type_category' => 'Market research'])
        ->assertOk();

    expect($directive->notes()->count())->toBe(0)->and(Notification::query()->count())->toBe(0);

    $this->actingAs($world->officer)
        ->patchJson("/api/v1/directives/{$directive->id}", ['type_category' => 'Trade fair'])
        ->assertOk()
        ->assertJsonPath('data.type_category', 'Trade fair');

    expect($directive->notes()->sole()->content)->toBe('Directive type changed from "Market research" to "Trade fair".');

    $this->actingAs($world->officer)->patchJson("/api/v1/directives/{$directive->id}", ['type_category' => null])->assertOk();
    expect($directive->fresh()->type_category)->toBeNull();
});

it('validates a revision and never lets it retarget the directive (TC-FR-DIR-013-R)', function (array $body, string $message) {
    $world = DirectiveWorld::create();
    $directive = $world->directive(['target_completion_date' => now()->addDays(10)->toDateString()]);

    $response = $this->actingAs($world->officer)->patchJson("/api/v1/directives/{$directive->id}", $body);

    $response->assertUnprocessable();
    expect(implode(' ', $response->json('errors')))->toContain($message)
        ->and($directive->fresh()->target_user_id)->toBe($world->attache->id)
        ->and($directive->fresh()->mission_id)->toBe($world->mission->id);
})->with([
    'empty body' => [[], 'Provide a new target completion date or directive type'],
    'past date' => [['target_completion_date' => '2020-01-01'], 'cannot be in the past'],
    'bad date format' => [['target_completion_date' => '12/10/2030'], 'format YYYY-MM-DD'],
    'type too long' => [['type_category' => str_repeat('t', 101)], '100 characters'],
    'target attache' => [['type_category' => 'X', 'target_user_id' => '0b5a51c5-7f6e-4a51-9c7c-1c2f1e5b0d11'], 'target attache of an issued directive cannot be changed'],
    'mission' => [['type_category' => 'X', 'mission_id' => '0b5a51c5-7f6e-4a51-9c7c-1c2f1e5b0d11'], 'target mission of an issued directive cannot be changed'],
    'description' => [['description' => 'Rewritten'], 'description of an issued directive cannot be changed'],
    'status' => [['status' => 'closed'], 'status endpoint'],
    'issuer' => [['issued_by_user_id' => '0b5a51c5-7f6e-4a51-9c7c-1c2f1e5b0d11'], 'issuer of a directive cannot be changed'],
    'completion summary' => [['completion_summary' => 'Done'], 'completion summary is recorded'],
]);

it('refuses to revise a finished directive (TC-FR-DIR-003-F)', function (string $status) {
    $world = DirectiveWorld::create();
    $directive = $world->directive(['status' => $status]);

    $this->actingAs($world->officer)
        ->patchJson("/api/v1/directives/{$directive->id}", ['type_category' => 'Late change'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.0', 'Only an open directive can be revised.');
})->with(['completed', 'cancelled', 'closed']);

it('reports allowed_actions for the requesting user from the policy and the state machine (TC-FR-DIR-014-A)', function (string $status, string $viewer, array $transitions, bool $addNote, bool $revise) {
    $world = DirectiveWorld::create();
    $directive = $world->directive(['status' => $status]);
    $user = match ($viewer) {
        'target' => $world->attache,
        'issuer' => $world->officer,
        default => $world->ps,
    };

    $this->actingAs($user)
        ->getJson("/api/v1/directives/{$directive->id}")
        ->assertOk()
        ->assertJsonPath('data.allowed_actions', ['transitions' => $transitions, 'add_note' => $addNote, 'revise' => $revise]);
})->with([
    'target, issued' => ['issued', 'target', ['acknowledged', 'in_progress'], true, false],
    'target, acknowledged' => ['acknowledged', 'target', ['in_progress'], true, false],
    'target, in progress' => ['in_progress', 'target', ['completed'], true, false],
    'target, completed' => ['completed', 'target', [], true, false],
    'issuer, issued' => ['issued', 'issuer', ['cancelled'], true, true],
    'issuer, in progress' => ['in_progress', 'issuer', ['cancelled'], true, true],
    'issuer, completed' => ['completed', 'issuer', ['closed'], true, false],
    'issuer, closed' => ['closed', 'issuer', [], true, false],
    'PS observer' => ['in_progress', 'ps', [], false, false],
]);

it('rebuilds a dated status history from the audit trail (TC-FR-DIR-014-H)', function () {
    $world = DirectiveWorld::create();

    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(9, 0));
    $id = $this->actingAs($world->officer)->postJson('/api/v1/directives', $world->issueBody())->assertCreated()->json('data.id');

    $this->travelTo(now()->setDate(2026, 10, 2)->setTime(9, 0));
    $this->actingAs($world->attache)->patchJson("/api/v1/directives/{$id}/status", ['status' => 'in_progress'])->assertOk();

    $this->travelTo(now()->setDate(2026, 10, 5)->setTime(9, 0));
    $this->actingAs($world->attache)->patchJson("/api/v1/directives/{$id}/status", ['status' => 'completed', 'note' => 'Done.'])->assertOk();

    $this->travelTo(now()->setDate(2026, 10, 6)->setTime(9, 0));
    $history = $this->actingAs($world->officer)->patchJson("/api/v1/directives/{$id}/status", ['status' => 'closed'])->assertOk()->json('data.status_history');

    expect(collect($history)->pluck('status')->all())->toBe(['issued', 'in_progress', 'completed', 'closed'])
        ->and(collect($history)->pluck('by.full_name')->all())->toBe(['Carol Officer', 'Amina Attache', 'Amina Attache', 'Carol Officer'])
        ->and(substr($history[1]['at'], 0, 10))->toBe('2026-10-02');
});

it('falls back to a single issued step when a directive has no audited status changes', function () {
    $world = DirectiveWorld::create();
    $directive = $world->directive();

    $history = $this->actingAs($world->officer)->getJson("/api/v1/directives/{$directive->id}")->assertOk()->json('data.status_history');

    expect($history)->toHaveCount(1)
        ->and($history[0]['status'])->toBe('issued')
        ->and($history[0]['by']['id'])->toBe($world->officer->id);
});

it('returns notes oldest-first with their kind (TC-FR-DIR-008-K)', function () {
    $world = DirectiveWorld::create();
    $directive = $world->directive(['status' => 'in_progress']);

    $this->travelTo(now()->subHours(3));
    DirectiveNote::factory()->create(['directive_id' => $directive->id, 'authored_by_user_id' => $world->attache->id, 'content' => 'First']);
    $this->travelBack();
    $this->travelTo(now()->subHours(2));
    DirectiveNote::factory()->create(['directive_id' => $directive->id, 'authored_by_user_id' => $world->officer->id, 'content' => 'Second']);
    $this->travelBack();
    DirectiveNote::factory()->create(['directive_id' => $directive->id, 'authored_by_user_id' => $world->ps->id, 'content' => 'Third']);

    $notes = $this->actingAs($world->director)->getJson("/api/v1/directives/{$directive->id}")->assertOk()->json('data.notes');

    expect(collect($notes)->pluck('content')->all())->toBe(['First', 'Second', 'Third'])
        ->and(collect($notes)->pluck('kind')->all())->toBe(['progress', 'follow_up', 'note']);
});

it('keeps a deactivated attache named on the directive detail (BR-002)', function () {
    $world = DirectiveWorld::create();
    $directive = $world->directive();
    $world->attache->delete();

    $this->actingAs($world->officer)
        ->getJson("/api/v1/directives/{$directive->id}")
        ->assertOk()
        ->assertJsonPath('data.target_user.full_name', 'Amina Attache');
});
