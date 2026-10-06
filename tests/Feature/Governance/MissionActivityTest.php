<?php

use Illuminate\Support\Carbon;
use Tests\Feature\Governance\GovernanceWorld;

/**
 * FR-HOM-001 to 003: the Head / Deputy Head of Mission activity feed and
 * summary metrics. Today is frozen at 2026-10-20: the current fiscal
 * quarter is Q2 2026 (October to December 2026) and the prior one Q1 2026
 * (July to September 2026).
 *
 * London's records: two alerts, three inquiries and two submitted reports
 * across two departments, plus a draft report and a directive that must
 * never appear (neither is an attache submission). Berlin's records must
 * never reach London's Head of Mission.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse(GovernanceWorld::TODAY));

    $world = GovernanceWorld::create();
    $this->world = $world;

    $this->tradeAlert = $world->alert($world->london, $world->trade, '2026-10-05 09:00:00', ['status' => 'new', 'country' => 'United Kingdom', 'intelligence_type' => 'opportunities', 'product_description' => 'UK retailers want certified avocados']);
    $this->agricultureAlert = $world->alert($world->london, $world->agriculture, '2026-08-12 14:00:00', ['status' => 'acknowledged']);
    $this->tradeInquiry = $world->inquiry($world->london, $world->trade, '2026-10-12 11:00:00', ['status' => 'received', 'inquirer_name' => 'Jane Buyer', 'inquirer_organisation' => 'Acme Imports', 'inquirer_email' => 'jane@acme.test']);
    $this->boundaryPrior = $world->inquiry($world->london, $world->agriculture, '2026-09-30 23:59:59', ['status' => 'closed']);
    $this->boundaryCurrent = $world->inquiry($world->london, $world->trade, '2026-10-01 00:00:00', ['status' => 'in_progress']);
    $this->onTimeReport = $world->report($world->london, $world->trade, 'Q1 2026', '2026-10-10 08:00:00');
    $this->lateReport = $world->report($world->london, $world->agriculture, 'Q4 2026', '2026-07-20 08:00:00', isLate: true);
    $this->draftReport = $world->report($world->london, $world->trade, 'Q2 2026', null);
    $this->directive = $world->directive($world->london, $world->trade, '2026-10-15 09:00:00');

    $this->berlinAlert = $world->alert($world->berlin, $world->trade, '2026-10-06 09:00:00');
    $this->berlinInquiry = $world->inquiry($world->berlin, $world->trade, '2026-10-07 09:00:00');

    $this->headOfMission = $world->member('Head of Mission');
});

// --- FR-HOM-001: the activity feed ---------------------------------------------

it('lists every department\'s submissions at the mission, newest first, and nothing else (TC-FR-HOM-001-A)', function () {
    $response = $this->actingAs($this->headOfMission)->getJson('/api/v1/mission-activity');

    $response->assertOk()->assertJsonPath('meta.total', 7);

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([
        $this->tradeInquiry->id,
        $this->onTimeReport->id,
        $this->tradeAlert->id,
        $this->boundaryCurrent->id,
        $this->boundaryPrior->id,
        $this->agricultureAlert->id,
        $this->lateReport->id,
    ]);
});

it('never lists a draft report, a directive or another mission\'s record (TC-FR-HOM-001-B)', function () {
    $ids = collect($this->actingAs($this->headOfMission)->getJson('/api/v1/mission-activity?per_page=100')->json('data'))->pluck('id');

    expect($ids)->not->toContain($this->draftReport->id)
        ->not->toContain($this->directive->id)
        ->not->toContain($this->berlinAlert->id)
        ->not->toContain($this->berlinInquiry->id);
});

it('shows each item\'s type, date, submitting officer, department, summary and link (TC-FR-HOM-001-C)', function () {
    $items = collect($this->actingAs($this->headOfMission)->getJson('/api/v1/mission-activity')->json('data'))->keyBy('id');

    $alert = $items->get($this->tradeAlert->id);
    expect($alert['type'])->toBe('alert')
        ->and($alert['reference'])->toBe($this->tradeAlert->reference_number)
        ->and($alert['status'])->toBe('new')
        ->and($alert['submitting_officer'])->toBe('Purity Samanthe')
        ->and($alert['ministry'])->toBe(['id' => $this->world->trade->id, 'name' => 'State Department for Trade'])
        ->and($alert['summary']['country'])->toBe('United Kingdom')
        ->and($alert['summary']['intelligence_type'])->toBe('opportunities')
        ->and($alert['summary']['excerpt'])->toBe('UK retailers want certified avocados')
        ->and($alert['link'])->toBe("/alerts/{$this->tradeAlert->id}")
        ->and(Carbon::parse($alert['date'])->toDateTimeString())->toBe('2026-10-05 09:00:00');

    $report = $items->get($this->lateReport->id);
    expect($report['type'])->toBe('periodic_report')
        ->and($report['status'])->toBe('submitted_late')
        ->and($report['submitting_officer'])->toBe('Joseph Kamau')
        ->and($report['summary'])->toMatchArray(['period_label' => 'Q4 2026', 'period_start' => '2026-04-01', 'period_end' => '2026-06-30', 'is_late' => true])
        ->and($report['link'])->toBe("/reports/{$this->lateReport->id}")
        ->and(Carbon::parse($report['date'])->toDateTimeString())->toBe('2026-07-20 08:00:00');
});

it('summarises an inquiry by its organisation, never the inquirer\'s personal details (TC-FR-HOM-001-D)', function () {
    $response = $this->actingAs($this->headOfMission)->getJson('/api/v1/mission-activity');
    $inquiry = collect($response->json('data'))->firstWhere('id', $this->tradeInquiry->id);

    expect($inquiry['summary']['inquirer_organisation'])->toBe('Acme Imports')
        ->and($inquiry['link'])->toBe("/inquiries/{$this->tradeInquiry->id}")
        ->and($response->getContent())->not->toContain('Jane Buyer')
        ->not->toContain('jane@acme.test');
});

it('filters the feed to one department (TC-FR-HOM-001-E, AC3)', function () {
    $response = $this->actingAs($this->headOfMission)->getJson("/api/v1/mission-activity?ministry_id={$this->world->agriculture->id}");

    $response->assertOk()->assertJsonPath('meta.total', 3);
    expect(collect($response->json('data'))->pluck('ministry.id')->unique()->all())->toBe([$this->world->agriculture->id]);
});

it('filters by record type, status and fiscal quarter, alone or together', function (string $query, int $expected) {
    $this->actingAs($this->headOfMission)
        ->getJson("/api/v1/mission-activity?{$query}")
        ->assertOk()
        ->assertJsonPath('meta.total', $expected);
})->with([
    'alerts' => ['type=alert', 2],
    'inquiries' => ['type=inquiry', 3],
    'reports' => ['type=periodic_report', 2],
    'one status' => ['status=received', 1],
    'late reports' => ['type=periodic_report&status=submitted_late', 1],
    'current quarter' => ['period=Q2%202026', 4],
    'prior quarter' => ['period=Q1%202026', 3],
    'quarter with no activity' => ['period=Q3%202026', 0],
    'type within a quarter' => ['type=inquiry&period=Q2%202026', 2],
]);

it('sorts oldest first on request and paginates with the standard meta', function () {
    $oldestFirst = $this->actingAs($this->headOfMission)->getJson('/api/v1/mission-activity?sort=date&per_page=2&page=2');

    $oldestFirst->assertOk()
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 7)
        ->assertJsonPath('meta.last_page', 4);

    expect(collect($oldestFirst->json('data'))->pluck('id')->all())->toBe([$this->boundaryPrior->id, $this->boundaryCurrent->id]);
});

it('ignores an attempt to name another mission: the mission is always the viewer\'s own', function () {
    $ids = collect($this->actingAs($this->headOfMission)
        ->getJson("/api/v1/mission-activity?mission_id={$this->world->berlin->id}&per_page=100")
        ->json('data'))->pluck('id');

    expect($ids)->toHaveCount(7)->not->toContain($this->berlinAlert->id);
});

it('rejects malformed filter values with 422 instead of passing them to a query', function (string $query) {
    $this->actingAs($this->headOfMission)
        ->getJson("/api/v1/mission-activity?{$query}")
        ->assertStatus(422)
        ->assertJsonPath('data', null);
})->with([
    'department not a uuid' => ['ministry_id=trade'],
    'unknown record type' => ['type=directive'],
    'status with markup' => ['status=%3Cscript%3E'],
    'period not a fiscal quarter' => ['period=2026-10'],
    'half-year instead of quarter' => ['period=H1%202026'],
    'unknown sort' => ['sort=reference'],
    'page below one' => ['page=0'],
    'page size above the maximum' => ['per_page=101'],
]);

// --- FR-HOM-002: summary metrics -------------------------------------------------

it('counts submissions by type and status for the current and prior quarter (TC-FR-HOM-002-A)', function () {
    $response = $this->actingAs($this->headOfMission)->getJson('/api/v1/mission-activity/summary');

    $response->assertOk()
        ->assertJsonPath('data.current_period.label', 'Q2 2026')
        ->assertJsonPath('data.current_period.start', '2026-10-01')
        ->assertJsonPath('data.current_period.end', '2026-12-31')
        ->assertJsonPath('data.current_period.is_partial', true)
        ->assertJsonPath('data.current_period.total', 4)
        ->assertJsonPath('data.current_period.by_type', ['alert' => 1, 'inquiry' => 2, 'periodic_report' => 1])
        ->assertJsonPath('data.current_period.by_status.alert', ['new' => 1])
        ->assertJsonPath('data.current_period.by_status.periodic_report', ['submitted_on_time' => 1])
        ->assertJsonPath('data.prior_period.label', 'Q1 2026')
        ->assertJsonPath('data.prior_period.is_partial', false)
        ->assertJsonPath('data.prior_period.total', 3)
        ->assertJsonPath('data.prior_period.by_type', ['alert' => 1, 'inquiry' => 1, 'periodic_report' => 1])
        ->assertJsonPath('data.prior_period.by_status.inquiry', ['closed' => 1])
        ->assertJsonPath('data.prior_period.by_status.periodic_report', ['submitted_late' => 1]);

    expect($response->json('data.current_period.by_status.inquiry'))->toEqualCanonicalizing(['received' => 1, 'in_progress' => 1]);
});

it('dates a report by its submission, not its reporting period, and puts quarter-boundary records in the right quarter (TC-FR-HOM-002-B)', function () {
    $current = $this->actingAs($this->headOfMission)->getJson('/api/v1/mission-activity?period=Q2%202026&per_page=100')->json('data');
    $prior = $this->actingAs($this->headOfMission)->getJson('/api/v1/mission-activity?period=Q1%202026&per_page=100')->json('data');

    expect(collect($current)->pluck('id'))->toContain($this->onTimeReport->id)->toContain($this->boundaryCurrent->id)
        ->and(collect($prior)->pluck('id'))->toContain($this->lateReport->id)->toContain($this->boundaryPrior->id);
});

it('lists the mission\'s departments with their posted attache and a six-quarter trend (TC-FR-HOM-002-C)', function () {
    $response = $this->actingAs($this->headOfMission)->getJson('/api/v1/mission-activity/summary');

    expect($response->json('data.mission'))->toMatchArray(['id' => $this->world->london->id, 'name' => 'London', 'host_country' => 'United Kingdom'])
        ->and($response->json('data.departments'))->toBe([
            ['id' => $this->world->agriculture->id, 'name' => 'State Department for Agriculture', 'active' => true, 'posted' => true, 'attache' => ['full_name' => 'Joseph Kamau'], 'has_activity' => true],
            ['id' => $this->world->trade->id, 'name' => 'State Department for Trade', 'active' => true, 'posted' => true, 'attache' => ['full_name' => 'Purity Samanthe'], 'has_activity' => true],
        ])
        ->and(collect($response->json('data.trend'))->pluck('label')->all())->toBe(['Q1 2025', 'Q2 2025', 'Q3 2026', 'Q4 2026', 'Q1 2026', 'Q2 2026'])
        ->and(collect($response->json('data.trend'))->pluck('total')->all())->toBe([0, 0, 0, 0, 3, 4])
        ->and(Carbon::parse($response->json('data.last_activity_at'))->toDateTimeString())->toBe('2026-10-12 11:00:00');
});

it('narrows the summary to one department when filtered', function () {
    $this->actingAs($this->headOfMission)
        ->getJson("/api/v1/mission-activity/summary?ministry_id={$this->world->trade->id}")
        ->assertOk()
        ->assertJsonPath('data.ministry_id', $this->world->trade->id)
        ->assertJsonPath('data.current_period.total', 4)
        ->assertJsonPath('data.prior_period.total', 0);
});

it('answers with zeros, not errors, for a mission with no activity yet', function () {
    $headOfMission = $this->world->member('Head of Mission', $this->world->closedMission);

    $this->actingAs($headOfMission)->getJson('/api/v1/mission-activity')
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.last_page', 1);

    $this->actingAs($headOfMission)->getJson('/api/v1/mission-activity/summary')
        ->assertOk()
        ->assertJsonPath('data.mission.active', false)
        ->assertJsonPath('data.current_period.total', 0)
        ->assertJsonPath('data.departments', [])
        ->assertJsonPath('data.last_activity_at', null);
});

// --- FR-HOM-003: the Deputy Head of Mission -------------------------------------

it('gives the Deputy Head of Mission exactly the Head of Mission\'s feed and summary, with no activation step (TC-FR-HOM-003)', function () {
    $deputy = $this->world->member('Deputy Head of Mission');

    $headFeed = $this->actingAs($this->headOfMission)->getJson('/api/v1/mission-activity?per_page=100')->json();
    $deputyFeed = $this->actingAs($deputy)->getJson('/api/v1/mission-activity?per_page=100')->json();
    $headSummary = $this->actingAs($this->headOfMission)->getJson('/api/v1/mission-activity/summary')->json();
    $deputySummary = $this->actingAs($deputy)->getJson('/api/v1/mission-activity/summary')->json();

    expect($deputyFeed)->toBe($headFeed)->and($deputySummary)->toBe($headSummary);
});
