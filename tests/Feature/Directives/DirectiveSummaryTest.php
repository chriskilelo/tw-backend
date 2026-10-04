<?php

use App\Models\Directive;
use App\Services\DirectiveService;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Directives\DirectiveWorld;

/**
 * FR-DIR-012: the director-level summary dashboard — counts, percentages
 * (including "no target date set"), per-mission and per-issuer drill-down,
 * filters and filter options. `completed` counts completed AND closed.
 */
beforeEach(function () {
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));

    $world = DirectiveWorld::create();
    $accra = ['mission_id' => $world->otherMission->id, 'target_user_id' => $world->otherAttache->id];
    $byDavid = ['issued_by_user_id' => $world->otherOfficer->id];

    $this->travelTo(now()->setDate(2026, 9, 5));
    $world->directive(['status' => 'issued', 'target_completion_date' => '2026-09-20']);
    $this->travelTo(now()->setDate(2026, 10, 1));
    $world->directive(['status' => 'in_progress', 'target_completion_date' => '2026-10-05', 'last_progress_update_at' => now()->subDays(20)]);
    $world->directive([...$accra, ...$byDavid, 'status' => 'completed', 'target_completion_date' => '2026-09-01']);
    $world->directive([...$accra, ...$byDavid, 'status' => 'closed']);
    $world->directive(['status' => 'cancelled', 'target_completion_date' => '2026-09-01']);
    $world->directive([...$byDavid, 'status' => 'acknowledged']);
    $world->directive([...$accra, 'status' => 'issued', 'target_completion_date' => '2026-12-01']);
    DirectiveWorld::create()->directive(['status' => 'in_progress', 'target_completion_date' => '2026-09-01']);

    $this->world = $world;
});

it('computes counts, percentages and breakdowns for the actor\'s ministry only (TC-FR-DIR-012-A)', function () {
    $world = $this->world;

    $data = $this->actingAs($world->director)->getJson('/api/v1/directives/summary')->assertOk()->json('data');

    expect(collect($data)->only(['total', 'completed', 'closed', 'in_progress', 'issued', 'acknowledged', 'cancelled', 'overdue', 'approaching', 'no_target_date', 'on_track', 'stale'])->all())
        ->toBe([
            'total' => 7, 'completed' => 2, 'closed' => 1, 'in_progress' => 1, 'issued' => 2, 'acknowledged' => 1,
            'cancelled' => 1, 'overdue' => 1, 'approaching' => 1, 'no_target_date' => 1, 'on_track' => 1, 'stale' => 2,
        ])
        ->and($data['percentages'])->toEqual(['completed' => 28.6, 'in_progress' => 14.3, 'overdue' => 14.3, 'no_target_date' => 14.3])
        ->and($data['by_mission'])->toEqual([
            ['mission_id' => $world->otherMission->id, 'mission_name' => 'Accra', 'total' => 3, 'completed' => 2, 'in_progress' => 0, 'overdue' => 0, 'completion_rate' => 66.7],
            ['mission_id' => $world->mission->id, 'mission_name' => 'Berlin', 'total' => 4, 'completed' => 0, 'in_progress' => 1, 'overdue' => 1, 'completion_rate' => 0],
        ])
        ->and($data['by_issuer'])->toBe([
            ['user_id' => $world->officer->id, 'full_name' => 'Carol Officer', 'total' => 4, 'completed' => 0, 'overdue' => 1],
            ['user_id' => $world->otherOfficer->id, 'full_name' => 'David Officer', 'total' => 3, 'completed' => 2, 'overdue' => 0],
        ])
        ->and($data['filter_options'])->toBe([
            'missions' => [['id' => $world->otherMission->id, 'name' => 'Accra'], ['id' => $world->mission->id, 'name' => 'Berlin']],
            'issuers' => [['id' => $world->officer->id, 'full_name' => 'Carol Officer'], ['id' => $world->otherOfficer->id, 'full_name' => 'David Officer']],
        ])
        ->and($data['filters'])->toBe(['date_from' => null, 'date_to' => null, 'mission_id' => null, 'issued_by_user_id' => null]);
});

it('applies the period, mission and issuer filters while keeping the filter options unfiltered (TC-FR-DIR-012-B)', function (Closure $query, int $total, int $completed) {
    $world = $this->world;

    $data = $this->actingAs($world->ps)->getJson('/api/v1/directives/summary?'.http_build_query($query($world)))->assertOk()->json('data');

    expect($data['total'])->toBe($total)
        ->and($data['completed'])->toBe($completed)
        ->and($data['filter_options']['missions'])->toHaveCount(2)
        ->and($data['filter_options']['issuers'])->toHaveCount(2)
        ->and(collect($data['filters'])->filter()->all())->toBe($query($world));
})->with([
    'mission' => [fn (DirectiveWorld $w) => ['mission_id' => $w->otherMission->id], 3, 2],
    'issuer' => [fn (DirectiveWorld $w) => ['issued_by_user_id' => $w->otherOfficer->id], 3, 2],
    'issued from' => [fn () => ['date_from' => '2026-09-10'], 6, 2],
    'issued until' => [fn () => ['date_to' => '2026-09-30'], 1, 0],
    'mission and issuer' => [fn (DirectiveWorld $w) => ['mission_id' => $w->mission->id, 'issued_by_user_id' => $w->otherOfficer->id], 1, 0],
]);

it('ignores invalid summary filters (TC-FR-DIR-012-C)', function () {
    $data = $this->actingAs($this->world->director)
        ->getJson('/api/v1/directives/summary?date_from=2026-02-30&date_to=tomorrow&mission_id=Berlin&issued_by_user_id=42')
        ->assertOk()
        ->json('data');

    expect($data['total'])->toBe(7)
        ->and($data['filters'])->toBe(['date_from' => null, 'date_to' => null, 'mission_id' => null, 'issued_by_user_id' => null]);
});

it('keeps the single-argument getSummary() working for the dashboards (TC-FR-DIR-012-D)', function () {
    $summary = app(DirectiveService::class)->getSummary($this->world->ministry->id);

    expect($summary['total'])->toBe(7)
        ->and($summary['completed'])->toBe(2)
        ->and($summary)->toHaveKeys(['percentages.completed', 'percentages.in_progress', 'percentages.overdue', 'by_mission', 'by_issuer', 'filter_options', 'filters']);
});

it('returns zeroed percentages for a ministry with no directives', function () {
    $empty = DirectiveWorld::create();

    $data = $this->actingAs($empty->ps)->getJson('/api/v1/directives/summary')->assertOk()->json('data');

    expect($data['total'])->toBe(0)
        ->and($data['percentages'])->toEqual(['completed' => 0, 'in_progress' => 0, 'overdue' => 0, 'no_target_date' => 0])
        ->and($data['by_mission'])->toBe([])
        ->and($data['filter_options'])->toBe(['missions' => [], 'issuers' => []]);

    expect(Directive::query()->withoutGlobalScopes()->count())->toBe(8);
});
