<?php

use App\Enums\UserStatus;
use App\Models\Directive;
use App\Models\Mission;
use App\Models\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Directives\DirectiveWorld;

/**
 * FR-DIR-002 (issue form and target validation), FR-DIR-003 (due-state
 * groupings, including the not-overdue-on-its-own-due-date fix), FR-DIR-005
 * and FR-DIR-009 (list narrowing, filters, search, sort, pagination).
 */
beforeEach(function () {
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));
});

// --- Issue (POST /directives) and the assignee picker ---

it('issues a directive with a validated target and returns the list shape with derived flags (TC-FR-DIR-002-A)', function () {
    $world = DirectiveWorld::create();

    $response = $this->actingAs($world->officer)->postJson('/api/v1/directives', $world->issueBody([
        'type_category' => 'Trade fair',
        'target_completion_date' => '2026-10-05',
    ]));

    $response->assertCreated()
        ->assertJsonPath('data.status', 'issued')
        ->assertJsonPath('data.mission.name', 'Berlin')
        ->assertJsonPath('data.target_user.id', $world->attache->id)
        ->assertJsonPath('data.issued_by.id', $world->officer->id)
        ->assertJsonPath('data.due_state', 'approaching')
        ->assertJsonPath('data.days_until_due', 4)
        ->assertJsonPath('data.is_overdue', false)
        ->assertJsonPath('data.is_stale', false);

    $directive = Directive::query()->findOrFail($response->json('data.id'));
    expect($directive->ministry_id)->toBe($world->ministry->id)
        ->and($directive->last_progress_update_at->isToday())->toBeTrue();

    $notification = Notification::query()->sole();
    expect($notification->recipient_user_id)->toBe($world->attache->id)
        ->and($notification->trigger_type)->toBe('directive_issued');
});

it('accepts a target date of today and no target date at all (TC-FR-DIR-003-A)', function () {
    $world = DirectiveWorld::create();

    $this->actingAs($world->officer)->postJson('/api/v1/directives', $world->issueBody(['target_completion_date' => '2026-10-01']))
        ->assertCreated()
        ->assertJsonPath('data.due_state', 'approaching')
        ->assertJsonPath('data.is_overdue', false);

    $this->actingAs($world->officer)->postJson('/api/v1/directives', $world->issueBody(['target_completion_date' => null]))
        ->assertCreated()
        ->assertJsonPath('data.due_state', 'no_date')
        ->assertJsonPath('data.days_until_due', null);
});

it('rejects an invalid issue request with a clear per-field message (TC-FR-DIR-002-V)', function (Closure $body, string $message) {
    $world = DirectiveWorld::create();

    $response = $this->actingAs($world->officer)->postJson('/api/v1/directives', $body($world));

    $response->assertUnprocessable();
    expect(implode(' ', $response->json('errors')))->toContain($message)
        ->and(Directive::query()->count())->toBe(0)
        ->and(Notification::query()->count())->toBe(0);
})->with([
    'no mission' => [fn (DirectiveWorld $w) => $w->issueBody(['mission_id' => null]), 'Select the mission'],
    'mission not a uuid' => [fn (DirectiveWorld $w) => $w->issueBody(['mission_id' => 'berlin']), 'selected mission is not valid'],
    'unknown mission' => [fn (DirectiveWorld $w) => $w->issueBody(['mission_id' => '0b5a51c5-7f6e-4a51-9c7c-1c2f1e5b0d11']), 'does not exist or is no longer active'],
    'inactive mission' => [function (DirectiveWorld $w) {
        $w->mission->update(['active' => false]);

        return $w->issueBody();
    }, 'does not exist or is no longer active'],
    'mission not linked to the ministry' => [fn (DirectiveWorld $w) => $w->issueBody(['mission_id' => Mission::factory()->create()->id]), 'not linked to your ministry'],
    'no target' => [fn (DirectiveWorld $w) => $w->issueBody(['target_user_id' => null]), 'Select the attache'],
    'target in another ministry' => [fn (DirectiveWorld $w) => $w->issueBody(['target_user_id' => DirectiveWorld::create()->attache->id]), 'not an active Ministry Attache in your ministry'],
    'target is not an attache' => [fn (DirectiveWorld $w) => $w->issueBody(['target_user_id' => $w->otherOfficer->id]), 'not an active Ministry Attache in your ministry'],
    'target deactivated' => [function (DirectiveWorld $w) {
        $w->attache->update(['status' => UserStatus::Deactivated]);

        return $w->issueBody();
    }, 'not an active Ministry Attache in your ministry'],
    'target posted at another mission' => [fn (DirectiveWorld $w) => $w->issueBody(['target_user_id' => $w->otherAttache->id]), 'not posted at the selected mission'],
    'no description' => [fn (DirectiveWorld $w) => $w->issueBody(['description' => '']), 'Describe what the directive asks'],
    'description too long' => [fn (DirectiveWorld $w) => $w->issueBody(['description' => str_repeat('d', 5001)]), '5000 characters'],
    'type too long' => [fn (DirectiveWorld $w) => $w->issueBody(['type_category' => str_repeat('t', 101)]), '100 characters'],
    'date in the past' => [fn (DirectiveWorld $w) => $w->issueBody(['target_completion_date' => '2026-09-30']), 'cannot be in the past'],
    'date in the wrong format' => [fn (DirectiveWorld $w) => $w->issueBody(['target_completion_date' => '01/11/2026']), 'format YYYY-MM-DD'],
]);

it('lists the ministry\'s active linked missions and their active attaches for the issue form (TC-FR-DIR-002-B)', function () {
    $world = DirectiveWorld::create();
    $emptyMission = DirectiveWorld::linkedMission($world->ministry, ['name' => 'Cairo', 'city' => 'Cairo', 'host_country' => 'Egypt']);
    $closedMission = DirectiveWorld::linkedMission($world->ministry, ['name' => 'Zurich', 'active' => false]);
    DirectiveWorld::user('Ministry Attache', $world->ministry, $closedMission);
    Mission::factory()->create(['name' => 'Unlinked']);
    $aaron = DirectiveWorld::user('Ministry Attache', $world->ministry, $world->mission, ['full_name' => 'Aaron Attache']);
    DirectiveWorld::user('Ministry Attache', $world->ministry, $world->mission, ['full_name' => 'Zed Deactivated', 'status' => UserStatus::Deactivated]);
    DirectiveWorld::user('Ministry HQ Officer', $world->ministry, $world->mission, ['full_name' => 'Hq At Mission']);
    $foreign = DirectiveWorld::create();
    DirectiveWorld::user('Ministry Attache', $foreign->ministry, $world->mission, ['full_name' => 'Foreign Attache']);

    $data = $this->actingAs($world->officer)->getJson('/api/v1/directives/assignees')->assertOk()->json('data');

    expect(collect($data)->pluck('name')->all())->toBe(['Accra', 'Berlin', 'Cairo'])
        ->and(collect($data)->firstWhere('name', 'Berlin')['attaches'])->toBe([
            ['id' => $aaron->id, 'full_name' => 'Aaron Attache'],
            ['id' => $world->attache->id, 'full_name' => 'Amina Attache'],
        ])
        ->and(collect($data)->firstWhere('name', 'Cairo'))->toBe([
            'id' => $emptyMission->id,
            'name' => 'Cairo',
            'city' => 'Cairo',
            'host_country' => 'Egypt',
            'attaches' => [],
        ]);
});

// --- List (GET /directives) ---

it('narrows the list per role: attache as target, officer as issuer, leadership the whole ministry (TC-FR-DIR-005, TC-FR-DIR-009)', function () {
    $world = DirectiveWorld::create();
    $mine = $world->directive();
    $otherTarget = $world->directive(['mission_id' => $world->otherMission->id, 'target_user_id' => $world->otherAttache->id]);
    $otherIssuer = $world->directive(['issued_by_user_id' => $world->otherOfficer->id]);
    DirectiveWorld::create()->directive();

    $ids = fn ($user) => collect($this->actingAs($user)->getJson('/api/v1/directives')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

    expect($ids($world->attache))->toBe(collect([$mine->id, $otherIssuer->id])->sort()->values()->all())
        ->and($ids($world->officer))->toBe(collect([$mine->id, $otherTarget->id])->sort()->values()->all())
        ->and($ids($world->ps))->toHaveCount(3)
        ->and($ids($world->director))->toHaveCount(3)
        ->and($ids($world->member('Acting PS')))->toHaveCount(3);
});

it('flags due states against today\'s date, never overdue on the due date itself (TC-FR-DIR-003-B, BR-017)', function (string $status, ?string $date, string $dueState, bool $overdue, ?int $days) {
    $world = DirectiveWorld::create();
    $directive = $world->directive(['status' => $status, 'target_completion_date' => $date]);

    $row = collect($this->actingAs($world->ps)->getJson('/api/v1/directives')->assertOk()->json('data'))->firstWhere('id', $directive->id);

    expect($row['due_state'])->toBe($dueState)
        ->and($row['is_overdue'])->toBe($overdue)
        ->and($row['days_until_due'])->toBe($days);
})->with([
    'due yesterday' => ['in_progress', '2026-09-30', 'overdue', true, -1],
    'due today' => ['in_progress', '2026-10-01', 'approaching', false, 0],
    'due in 7 days' => ['acknowledged', '2026-10-08', 'approaching', false, 7],
    'due in 8 days' => ['issued', '2026-10-09', 'on_track', false, 8],
    'no date' => ['issued', null, 'no_date', false, null],
    'completed after its date' => ['completed', '2026-09-01', 'completed', false, -30],
    'closed after its date' => ['closed', '2026-09-01', 'completed', false, -30],
    'cancelled after its date' => ['cancelled', '2026-09-01', 'cancelled', false, -30],
]);

it('filters the list by status, mission, issuer and issue date, ignoring invalid values (TC-FR-DIR-009-F)', function () {
    $world = DirectiveWorld::create();
    $this->travelTo(now()->setDate(2026, 9, 10));
    $september = $world->directive(['status' => 'in_progress']);
    $this->travelTo(now()->setDate(2026, 10, 1));
    $accra = $world->directive(['mission_id' => $world->otherMission->id, 'target_user_id' => $world->otherAttache->id]);
    $byOther = $world->directive(['issued_by_user_id' => $world->otherOfficer->id, 'status' => 'completed']);

    $ids = fn (string $query) => collect($this->actingAs($world->ps)->getJson("/api/v1/directives?{$query}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    $sorted = fn (...$directives) => collect($directives)->pluck('id')->sort()->values()->all();

    expect($ids('status=in_progress'))->toBe($sorted($september))
        ->and($ids('status=completed'))->toBe($sorted($byOther))
        ->and($ids("mission_id={$world->otherMission->id}"))->toBe($sorted($accra))
        ->and($ids("issued_by_user_id={$world->otherOfficer->id}"))->toBe($sorted($byOther))
        ->and($ids('date_from=2026-10-01'))->toBe($sorted($accra, $byOther))
        ->and($ids('date_to=2026-09-30'))->toBe($sorted($september))
        ->and($ids('date_from=2026-09-01&date_to=2026-09-30'))->toBe($sorted($september))
        ->and($ids('status=bogus&mission_id=not-a-uuid&issued_by_user_id=7&date_from=2026-13-45&date_to=yesterday&due=soon&sort=random&stale=maybe&unknown=1'))
        ->toBe($sorted($september, $accra, $byOther));
});

it('filters the list by due state and staleness (TC-FR-DIR-003-C, TC-FR-DIR-010-F)', function () {
    $world = DirectiveWorld::create();
    $overdue = $world->directive(['status' => 'in_progress', 'target_completion_date' => '2026-09-28']);
    $approaching = $world->directive(['target_completion_date' => '2026-10-08']);
    $onTrack = $world->directive(['target_completion_date' => '2026-12-01']);
    $noDate = $world->directive(['status' => 'acknowledged', 'last_progress_update_at' => now()->subDays(15)]);
    $world->directive(['status' => 'completed', 'target_completion_date' => '2026-09-01', 'last_progress_update_at' => now()->subDays(40)]);
    $world->directive(['status' => 'cancelled', 'target_completion_date' => null]);

    $ids = fn (string $query) => collect($this->actingAs($world->director)->getJson("/api/v1/directives?{$query}")->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('due=overdue'))->toBe([$overdue->id])
        ->and($ids('due=approaching'))->toBe([$approaching->id])
        ->and($ids('due=on_track'))->toBe([$onTrack->id])
        ->and($ids('due=no_date'))->toBe([$noDate->id])
        ->and($ids('stale=1'))->toBe([$noDate->id])
        ->and($ids('stale=true'))->toBe([$noDate->id])
        ->and($ids('stale=0'))->toHaveCount(6);
});

it('searches description and type case-insensitively, treating % and _ literally (TC-FR-DIR-009-S)', function () {
    $world = DirectiveWorld::create();
    $percent = $world->directive(['description' => 'Raise tea exports by 10% in Q3.']);
    $underscore = $world->directive(['description' => 'Update the file buyer_list.xlsx.']);
    $typed = $world->directive(['description' => 'Attend the expo.', 'type_category' => 'Coffee Promotion']);
    $world->directive(['description' => 'Raise tea exports by 10 points and compile the buyerXlist.']);

    $ids = fn (string $q) => collect($this->actingAs($world->ps)->getJson('/api/v1/directives?q='.urlencode($q))->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('10%'))->toBe([$percent->id])
        ->and($ids('buyer_list'))->toBe([$underscore->id])
        ->and($ids('COFFEE promo'))->toBe([$typed->id])
        ->and($ids('%'))->toBe([$percent->id])
        ->and($ids('   '))->toHaveCount(4);
});

it('sorts the list, keeping directives without a date last in both directions (TC-FR-DIR-009-O)', function () {
    $world = DirectiveWorld::create();
    $this->travelTo(now()->subDays(3));
    $oldest = $world->directive(['target_completion_date' => '2026-11-01', 'last_progress_update_at' => now()]);
    $this->travelTo(now()->addDay());
    $noDate = $world->directive(['target_completion_date' => null, 'last_progress_update_at' => now()]);
    $this->travelTo(now()->addDay());
    $newest = $world->directive(['target_completion_date' => '2026-10-10', 'last_progress_update_at' => now()->subDays(30)]);

    $ids = fn (string $sort) => collect($this->actingAs($world->ps)->getJson("/api/v1/directives?sort={$sort}")->assertOk()->json('data'))->pluck('id')->all();

    expect($ids(''))->toBe([$newest->id, $noDate->id, $oldest->id])
        ->and($ids('-created_at'))->toBe([$newest->id, $noDate->id, $oldest->id])
        ->and($ids('created_at'))->toBe([$oldest->id, $noDate->id, $newest->id])
        ->and($ids('target_completion_date'))->toBe([$newest->id, $oldest->id, $noDate->id])
        ->and($ids('-target_completion_date'))->toBe([$oldest->id, $newest->id, $noDate->id])
        ->and($ids('-last_progress_update_at'))->toBe([$noDate->id, $oldest->id, $newest->id])
        ->and($ids('last_progress_update_at'))->toBe([$newest->id, $oldest->id, $noDate->id]);
});

it('paginates with a clamped per_page and reports the page meta (TC-FR-DIR-009-P)', function () {
    $world = DirectiveWorld::create();
    foreach (range(1, 5) as $i) {
        $world->directive();
    }

    $this->actingAs($world->ps)->getJson('/api/v1/directives?per_page=2&page=3')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta', ['current_page' => 3, 'per_page' => 2, 'total' => 5, 'last_page' => 3]);

    $this->actingAs($world->ps)->getJson('/api/v1/directives?per_page=0')->assertJsonPath('meta.per_page', 1);
    $this->actingAs($world->ps)->getJson('/api/v1/directives?per_page=500')->assertJsonPath('meta.per_page', 100);
    $this->actingAs($world->ps)->getJson('/api/v1/directives?per_page=lots')->assertJsonPath('meta.per_page', 25);
});
