<?php

use Illuminate\Support\Carbon;
use Tests\Feature\Governance\GovernanceWorld;

/**
 * FR-MFA-001 to 003: the MFA awareness view. Aggregate counts by mission,
 * department and fiscal quarter; a metadata-only submission log; a mission
 * drill-down equal to the Head of Mission's summary; and the Principal
 * Secretary's national comparison. Today is frozen at 2026-10-20 (Q2 2026).
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse(GovernanceWorld::TODAY));

    $world = GovernanceWorld::create();
    $this->world = $world;

    // London: 3 this quarter (2 Trade, 1 Agriculture), 1 last quarter.
    $this->londonAlert = $world->alert($world->london, $world->trade, '2026-10-05 09:00:00', ['country' => 'United Kingdom', 'product_description' => 'Secret pricing intelligence']);
    $world->inquiry($world->london, $world->trade, '2026-10-12 11:00:00', ['inquirer_name' => 'Jane Buyer', 'description' => 'Wants 40 tonnes of tea']);
    $world->report($world->london, $world->agriculture, 'Q1 2026', '2026-10-10 08:00:00');
    $world->alert($world->london, $world->agriculture, '2026-08-12 14:00:00');

    // Berlin: 1 this quarter, 2 in older quarters.
    $world->alert($world->berlin, $world->trade, '2026-10-06 09:00:00');
    $world->inquiry($world->berlin, $world->trade, '2026-02-03 09:00:00');
    $world->report($world->berlin, $world->trade, 'Q4 2026', '2026-07-25 08:00:00', isLate: true);

    // Never counted: a draft report and a directive.
    $world->report($world->berlin, $world->trade, 'Q1 2026', null);
    $world->directive($world->london, $world->trade, '2026-10-15 09:00:00');

    $this->officer = $world->member('MFA HQ Officer');
    $this->principalSecretary = $world->member('MFA Principal Secretary');
});

// --- FR-MFA-001: aggregate counts -------------------------------------------------

it('counts submissions to date by type, mission, department and quarter (TC-FR-MFA-001-A)', function () {
    $response = $this->actingAs($this->officer)->getJson('/api/v1/mfa-awareness');

    $response->assertOk()
        ->assertJsonPath('data.total', 7)
        ->assertJsonPath('data.by_type', ['alert' => 3, 'inquiry' => 2, 'periodic_report' => 2])
        ->assertJsonPath('data.scope', ['ministry_id' => null, 'period' => null])
        ->assertJsonPath('data.current_quarter.label', 'Q2 2026');

    $byMission = collect($response->json('data.by_mission'))->keyBy('mission_name');
    expect($byMission->keys()->all())->toBe(['London', 'Berlin'])
        ->and($byMission['London']['total'])->toBe(4)
        ->and($byMission['London']['by_type'])->toBe(['alert' => 2, 'inquiry' => 1, 'periodic_report' => 1])
        ->and($byMission['Berlin']['total'])->toBe(3)
        ->and(Carbon::parse($byMission['London']['last_activity_at'])->toDateTimeString())->toBe('2026-10-12 11:00:00');

    $byMinistry = collect($response->json('data.by_ministry'))->keyBy('ministry_name');
    expect($byMinistry['State Department for Trade']['total'])->toBe(5)
        ->and($byMinistry['State Department for Trade']['missions_reporting'])->toBe(2)
        ->and($byMinistry['State Department for Agriculture']['total'])->toBe(2)
        ->and($byMinistry['State Department for Agriculture']['missions_reporting'])->toBe(1);

    $byPeriod = collect($response->json('data.by_period'));
    expect($byPeriod)->toHaveCount(8)
        ->and($byPeriod->last()['label'])->toBe('Q2 2026')
        ->and($byPeriod->last()['is_partial'])->toBeTrue()
        ->and($byPeriod->pluck('total', 'label')->only(['Q3 2026', 'Q4 2026', 'Q1 2026', 'Q2 2026'])->all())
        ->toBe(['Q3 2026' => 1, 'Q4 2026' => 0, 'Q1 2026' => 2, 'Q2 2026' => 4]);
});

it('never exposes record content, references, officers or record ids (TC-FR-MFA-001-B, AC2)', function () {
    foreach (['/api/v1/mfa-awareness', '/api/v1/mfa-awareness/submissions?per_page=100', "/api/v1/mfa-awareness/missions/{$this->world->london->id}"] as $uri) {
        $body = $this->actingAs($this->officer)->getJson($uri)->assertOk()->getContent();

        expect($body)->not->toContain($this->londonAlert->reference_number)
            ->not->toContain($this->londonAlert->id)
            ->not->toContain('Secret pricing intelligence')
            ->not->toContain('Jane Buyer')
            ->not->toContain('Wants 40 tonnes of tea')
            ->not->toContain('Purity Samanthe')
            ->not->toContain('Joseph Kamau')
            ->not->toContain('Confidential HQ tasking');
    }
});

it('narrows every breakdown to one department', function () {
    $response = $this->actingAs($this->officer)->getJson("/api/v1/mfa-awareness?ministry_id={$this->world->agriculture->id}");

    $response->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.scope.ministry_id', $this->world->agriculture->id)
        ->assertJsonCount(1, 'data.by_ministry');

    expect(collect($response->json('data.by_mission'))->pluck('total', 'mission_name')->all())->toBe(['London' => 2, 'Berlin' => 0])
        ->and(collect($response->json('data.ministries'))->pluck('name')->all())->toBe(['State Department for Agriculture', 'State Department for Trade']);
});

it('limits the totals to one quarter while the quarterly trend keeps its eight quarters', function () {
    $response = $this->actingAs($this->officer)->getJson('/api/v1/mfa-awareness?period=Q2%202026');

    $response->assertOk()
        ->assertJsonPath('data.total', 4)
        ->assertJsonPath('data.scope.period.label', 'Q2 2026')
        ->assertJsonCount(8, 'data.by_period');

    expect(collect($response->json('data.by_mission'))->pluck('total', 'mission_name')->all())->toBe(['London' => 3, 'Berlin' => 1]);
});

// --- FR-MFA-001 AC2: the metadata-only submission log ---------------------------

it('logs each submission as type, date, mission and department only, newest first (TC-FR-MFA-001-C)', function () {
    $response = $this->actingAs($this->officer)->getJson('/api/v1/mfa-awareness/submissions');

    $response->assertOk()->assertJsonPath('meta.total', 7);

    $first = $response->json('data.0');
    expect(array_keys($first))->toBe(['type', 'date', 'mission', 'ministry'])
        ->and($first['type'])->toBe('inquiry')
        ->and($first['mission'])->toBe(['id' => $this->world->london->id, 'name' => 'London'])
        ->and($first['ministry'])->toBe(['id' => $this->world->trade->id, 'name' => 'State Department for Trade'])
        ->and(Carbon::parse($first['date'])->toDateTimeString())->toBe('2026-10-12 11:00:00');
});

it('filters the log by mission, department, type and quarter', function (string $query, int $expected) {
    $query = strtr($query, [':berlin' => $this->world->berlin->id, ':agriculture' => $this->world->agriculture->id]);

    $this->actingAs($this->officer)
        ->getJson("/api/v1/mfa-awareness/submissions?{$query}")
        ->assertOk()
        ->assertJsonPath('meta.total', $expected);
})->with([
    'one mission' => ['mission_id=:berlin', 3],
    'one department' => ['ministry_id=:agriculture', 2],
    'one type' => ['type=periodic_report', 2],
    'one quarter' => ['period=Q1%202026', 2],
    'combined' => ['mission_id=:berlin&type=alert&period=Q2%202026', 1],
]);

it('ignores a status or free-text filter on the log, which would reveal what an entry hides', function () {
    $this->actingAs($this->officer)
        ->getJson('/api/v1/mfa-awareness/submissions?status=new&q=tea')
        ->assertOk()
        ->assertJsonPath('meta.total', 7);
});

// --- FR-MFA-002: the mission drill-down ------------------------------------------

it('gives a mission\'s summary exactly as its Head of Mission sees it, without attache names (TC-FR-MFA-002)', function () {
    $drillDown = $this->actingAs($this->officer)->getJson("/api/v1/mfa-awareness/missions/{$this->world->london->id}")->assertOk();
    $headOfMission = $this->actingAs($this->world->member('Head of Mission'))->getJson('/api/v1/mission-activity/summary')->assertOk();

    foreach (['mission', 'current_period', 'prior_period', 'trend', 'last_activity_at'] as $key) {
        expect($drillDown->json("data.{$key}"))->toBe($headOfMission->json("data.{$key}"));
    }

    expect(collect($drillDown->json('data.departments'))->pluck('attache')->all())->toBe([null, null])
        ->and(collect($drillDown->json('data.departments'))->pluck('posted')->all())->toBe([true, true])
        ->and(collect($drillDown->json('data.recent'))->first())->toHaveKeys(['type', 'date', 'mission', 'ministry'])
        ->and(collect($drillDown->json('data.recent'))->pluck('mission.name')->unique()->all())->toBe(['London']);
});

it('drills into an inactive mission and answers 404 for one that does not exist', function () {
    $this->actingAs($this->officer)
        ->getJson("/api/v1/mfa-awareness/missions/{$this->world->closedMission->id}")
        ->assertOk()
        ->assertJsonPath('data.mission.active', false)
        ->assertJsonPath('data.current_period.total', 0);

    $this->actingAs($this->officer)->getJson('/api/v1/mfa-awareness/missions/0198f5a2-0000-7000-8000-000000000000')->assertNotFound();
    $this->actingAs($this->officer)->getJson('/api/v1/mfa-awareness/missions/not-a-uuid')->assertNotFound();
});

// --- FR-MFA-003: the national overview -------------------------------------------

it('compares every mission and department for a quarter against the one before (TC-FR-MFA-003-A)', function () {
    $response = $this->actingAs($this->principalSecretary)->getJson('/api/v1/mfa-awareness/national-overview');

    $response->assertOk()
        ->assertJsonPath('data.period.label', 'Q2 2026')
        ->assertJsonPath('data.comparison_period.label', 'Q1 2026')
        ->assertJsonPath('data.totals.current.total', 4)
        ->assertJsonPath('data.totals.prior.total', 2);

    $missions = collect($response->json('data.missions'))->keyBy('mission_name');
    expect($missions->keys()->all())->toBe(['Berlin', 'London'])
        ->and($missions['London']['current']['total'])->toBe(3)
        ->and($missions['London']['current']['by_ministry'])->toBe([$this->world->agriculture->id => 1, $this->world->trade->id => 2])
        ->and($missions['London']['prior']['total'])->toBe(1)
        ->and($missions['Berlin']['current']['total'])->toBe(1)
        ->and($missions['Berlin']['prior']['total'])->toBe(1);

    $departments = collect($response->json('data.departments'))->keyBy('ministry_name');
    expect($departments['State Department for Trade']['current'])->toMatchArray(['total' => 3, 'missions_reporting' => 2])
        ->and($departments['State Department for Agriculture']['current'])->toMatchArray(['total' => 1, 'missions_reporting' => 1]);
});

it('compares any chosen quarter with the quarter before it (TC-FR-MFA-003-B)', function () {
    $this->actingAs($this->principalSecretary)
        ->getJson('/api/v1/mfa-awareness/national-overview?period=Q1%202026')
        ->assertOk()
        ->assertJsonPath('data.period.label', 'Q1 2026')
        ->assertJsonPath('data.period.is_partial', false)
        ->assertJsonPath('data.comparison_period.label', 'Q4 2026')
        ->assertJsonPath('data.totals.current.total', 2)
        ->assertJsonPath('data.totals.prior.total', 0);
});

it('rejects a malformed department or quarter with 422', function (string $uri) {
    $this->actingAs($this->principalSecretary)->getJson($uri)->assertStatus(422);
})->with([
    '/api/v1/mfa-awareness?ministry_id=42',
    '/api/v1/mfa-awareness?period=Q5%202026',
    '/api/v1/mfa-awareness/submissions?type=directive',
    '/api/v1/mfa-awareness/submissions?per_page=500',
    '/api/v1/mfa-awareness/national-overview?period=last-quarter',
]);
