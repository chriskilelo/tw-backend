<?php

use App\Models\Directive;
use App\Models\MissionMinistryLink;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Directives\DirectiveWorld;

/**
 * The SDT console's directive oversight board (GET /sdt/directives/overview
 * and /compliance): FR-SDT-001 (the PS's national directive summary),
 * FR-SDT-008/010 (the directors' read-only oversight of every mission's
 * directives), FR-DIR-003, FR-DIR-010 and FR-DIR-012. The board only reads;
 * acting on a directive stays with its issuer and target.
 */
const DIRECTIVE_OVERVIEW_ENDPOINTS = ['/api/v1/sdt/directives/overview', '/api/v1/sdt/directives/compliance'];

beforeEach(function () {
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(10, 0));
    $this->world = DirectiveWorld::create();
});

// --- Access ---------------------------------------------------------------

it('opens the board to the Ministry HQ Director, the PS and the Acting PS (TC-FR-SDT-008-A)', function (string $roleName) {
    $viewer = $this->world->member($roleName);

    foreach (DIRECTIVE_OVERVIEW_ENDPOINTS as $endpoint) {
        $this->actingAs($viewer)->getJson($endpoint)->assertOk();
    }
})->with(['Ministry HQ Director', 'Ministry PS', 'Acting PS']);

it('refuses every other role (TC-FR-SDT-008-B)', function (string $roleName) {
    $viewer = $this->world->member($roleName);

    foreach (DIRECTIVE_OVERVIEW_ENDPOINTS as $endpoint) {
        $this->actingAs($viewer)->getJson($endpoint)->assertForbidden();
    }
})->with([
    'Ministry HQ Officer', 'Ministry Attache', 'Ministry Publishing Authority', 'Designated Deputy', 'HRM&D Officer',
    'Ministry Administrator', 'System Administrator', 'Head of Mission', 'Deputy Head of Mission', 'MFA HQ Officer', 'MFA Principal Secretary',
]);

it('refuses a director with no department, and an anonymous caller (TC-FR-SDT-008-C)', function () {
    $stray = DirectiveWorld::user('Ministry HQ Director', null);

    foreach (DIRECTIVE_OVERVIEW_ENDPOINTS as $endpoint) {
        $this->actingAs($stray)->getJson($endpoint)->assertForbidden();
    }

    auth()->logout();
    $this->getJson(DIRECTIVE_OVERVIEW_ENDPOINTS[0])->assertUnauthorized();
});

it('authorises before validating, and rejects out-of-department filters (TC-FR-SDT-008-D)', function () {
    $world = $this->world;
    $other = DirectiveWorld::create();

    $this->actingAs($world->officer)->getJson('/api/v1/sdt/directives/overview?mission_id=not-a-uuid')->assertForbidden();

    foreach ([
        'mission_id=not-a-uuid',
        "mission_id={$other->mission->id}",
        "issued_by_user_id={$other->officer->id}",
        'issued_by_user_id[]=x',
    ] as $query) {
        $this->actingAs($world->director)->getJson("/api/v1/sdt/directives/overview?{$query}")->assertUnprocessable();
    }
});

// --- Content ------------------------------------------------------------------

it('counts what needs attention from the department\'s own directives only (TC-FR-DIR-003-OV)', function () {
    $world = $this->world;
    $world->directive(['status' => 'issued', 'target_completion_date' => '2026-10-10']);
    $world->directive(['status' => 'acknowledged', 'target_completion_date' => '2026-10-18']);
    $world->directive(['status' => 'in_progress', 'last_progress_update_at' => now()->subDays(20)]);
    $world->directive(['status' => 'completed']);
    $world->directive(['status' => 'closed']);
    $world->directive(['status' => 'cancelled']);
    DirectiveWorld::create()->directive(['status' => 'issued', 'target_completion_date' => '2026-10-01']);

    $data = $this->actingAs($world->director)->getJson('/api/v1/sdt/directives/overview')->assertOk()->json('data');

    expect($data['attention'])->toBe(['open' => 3, 'overdue' => 1, 'approaching' => 1, 'stale' => 1, 'no_date' => 1, 'awaiting_closure' => 1])
        ->and($data['summary']['total'])->toBe(6)
        ->and($data['my_follow_through'])->toBeNull();
});

it('ranks missions worst first and includes posted missions with nothing yet (TC-FR-SDT-009-OV)', function () {
    $world = $this->world;
    MissionMinistryLink::query()->where('mission_id', $world->mission->id)->update(['active_attache_user_id' => $world->attache->id]);
    $quiet = DirectiveWorld::linkedMission($world->ministry, ['name' => 'Cairo']);
    $world->directive(['status' => 'issued', 'target_completion_date' => '2026-10-01']);
    $world->directive(['mission_id' => $world->otherMission->id, 'target_user_id' => $world->otherAttache->id, 'status' => 'completed']);

    $missions = collect($this->actingAs($world->ps)->getJson('/api/v1/sdt/directives/overview')->json('data.missions'));

    expect($missions->pluck('mission_name')->all())->toBe(['Berlin', 'Accra', 'Cairo'])
        ->and($missions->pluck('health')->all())->toBe(['attention', 'watch', 'idle'])
        ->and($missions[0]['attache']['full_name'])->toBe('Amina Attache')
        ->and($missions[0]['overdue'])->toBe(1)
        ->and($missions[1]['awaiting_closure'])->toBe(1)
        ->and($missions[1]['completion_rate'])->toBe(100)
        ->and($missions[2]['mission_id'])->toBe($quiet->id)
        ->and($missions[2]['total'])->toBe(0);
});

it('shows each issuer\'s follow-through and the viewer\'s own when they issued any (TC-FR-DIR-011-OV)', function () {
    $world = $this->world;
    $world->directive(['status' => 'completed']);
    $world->directive(['status' => 'completed']);
    $world->directive(['issued_by_user_id' => $world->ps->id, 'status' => 'in_progress', 'last_progress_update_at' => now()->subDays(30)]);

    $data = $this->actingAs($world->ps)->getJson('/api/v1/sdt/directives/overview')->json('data');

    expect(collect($data['issuers'])->pluck('full_name')->all())->toBe(['Carol Officer', 'Esther PS'])
        ->and($data['issuers'][0]['awaiting_closure'])->toBe(2)
        ->and($data['my_follow_through'])->toBe(['open' => 1, 'overdue' => 0, 'approaching' => 0, 'stale' => 1, 'no_date' => 1, 'awaiting_closure' => 0]);
});

it('filters every part of the board by mission and issuer (TC-FR-DIR-012-OV)', function () {
    $world = $this->world;
    $world->directive();
    $accra = $world->directive(['mission_id' => $world->otherMission->id, 'target_user_id' => $world->otherAttache->id, 'issued_by_user_id' => $world->otherOfficer->id]);
    $this->actingAs($world->otherAttache)->patchJson("/api/v1/directives/{$accra->id}/status", ['status' => 'acknowledged'])->assertOk();

    $byMission = $this->actingAs($world->director)->getJson("/api/v1/sdt/directives/overview?mission_id={$world->otherMission->id}")->json('data');
    $byIssuer = $this->actingAs($world->director)->getJson("/api/v1/sdt/directives/overview?issued_by_user_id={$world->officer->id}")->json('data');

    expect($byMission['summary']['total'])->toBe(1)
        ->and(collect($byMission['missions'])->pluck('mission_name')->all())->toBe(['Accra'])
        ->and(collect($byMission['recent_activity'])->pluck('directive_id')->unique()->all())->toBe([$accra->id])
        ->and($byMission['filters'])->toBe(['mission_id' => $world->otherMission->id, 'issued_by_user_id' => null])
        ->and($byIssuer['summary']['total'])->toBe(1)
        ->and(collect($byIssuer['issuers'])->pluck('full_name')->all())->toBe(['Carol Officer'])
        ->and($byIssuer['recent_activity'])->toBe([]);
});

it('lists real workflow steps only, newest first, never a progress note or a revision (TC-FR-DIR-014-OV)', function () {
    $world = $this->world;

    $this->travelTo(now()->setTime(9, 0));
    $id = $this->actingAs($world->officer)->postJson('/api/v1/directives', $world->issueBody(['target_completion_date' => '2026-11-30']))->assertCreated()->json('data.id');
    $this->travelTo(now()->setTime(10, 0));
    $this->actingAs($world->attache)->patchJson("/api/v1/directives/{$id}/status", ['status' => 'in_progress'])->assertOk();
    $this->travelTo(now()->setTime(11, 0));
    $this->actingAs($world->attache)->postJson("/api/v1/directives/{$id}/notes", ['content' => 'Met the importers.'])->assertCreated();
    $this->travelTo(now()->setTime(12, 0));
    $this->actingAs($world->officer)->patchJson("/api/v1/directives/{$id}", ['target_completion_date' => '2026-12-15'])->assertOk();

    $activity = $this->actingAs($world->ps)->getJson('/api/v1/sdt/directives/overview')->json('data.recent_activity');

    expect(collect($activity)->pluck('status')->all())->toBe(['in_progress', 'issued'])
        ->and($activity[0]['from_status'])->toBe('issued')
        ->and($activity[0]['by']['full_name'])->toBe('Amina Attache')
        ->and($activity[0]['mission']['name'])->toBe('Berlin');

    $history = $this->actingAs($world->officer)->getJson("/api/v1/directives/{$id}")->json('data.status_history');
    expect(collect($history)->pluck('status')->all())->toBe(['issued', 'in_progress']);
});

it('keeps the compliance board\'s mission rows identical to the overview\'s (TC-FR-SDT-009-B)', function () {
    $world = $this->world;
    $world->directive(['status' => 'issued', 'target_completion_date' => '2026-10-01']);

    $overview = $this->actingAs($world->ps)->getJson('/api/v1/sdt/directives/overview')->json('data.missions');
    $compliance = $this->actingAs($world->ps)->getJson('/api/v1/sdt/directives/compliance')->json('data');

    expect($compliance['missions'])->toBe($overview)
        ->and($compliance['summary']['total'])->toBe(1);
});

it('never counts another department\'s directive, whatever its mission (TC-NFR-SEC-006-DOV)', function () {
    $world = $this->world;
    $other = DirectiveWorld::create();
    Directive::factory()->create([
        'ministry_id' => $other->ministry->id, 'mission_id' => $world->mission->id, 'target_user_id' => $other->attache->id,
        'issued_by_user_id' => $other->officer->id, 'status' => 'issued', 'last_progress_update_at' => now(),
    ]);

    $data = $this->actingAs($world->ps)->getJson('/api/v1/sdt/directives/overview')->json('data');

    expect($data['summary']['total'])->toBe(0)
        ->and($data['issuers'])->toBe([])
        ->and($data['recent_activity'])->toBe([])
        ->and(collect($data['missions'])->sum('total'))->toBe(0);
});
