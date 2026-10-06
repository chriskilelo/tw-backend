<?php

use App\Models\Ministry;
use App\Models\PeriodicReport;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Reports\ReportWorld;

/**
 * URD Section 10.2 / API-001 Section 5: who may do what in the Periodic
 * Report Engine, checked on the API itself (hiding a button is never the
 * control). Only the attache of the report's own mission writes, and only
 * while it is a draft (BR-001, BR-009); HQ review roles read submitted
 * reports (FR-RPT-017, FR-SDT-007, FR-SDT-015); Heads of Mission read their
 * own mission's submitted reports (FR-HOM-001); the four BR-020 roles never
 * write; the MFA roles, the HRM&D Officer, the Ministry Administrator and
 * the System Administrator never see report content.
 *
 * Pinned to 5 October 2026 (Q1 2026 open for submission, Q2 in progress).
 */
beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-05 09:00:00'));
});

/**
 * Every run gets fresh reports so each mutating endpoint acts on its own
 * draft: a submitted Q1 2025 (the carry-forward source), and drafts for
 * Q1 2026, Q4 2026, Q3 2026 and Q2 2025.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function reportMatrixEndpoints(ReportWorld $world): array
{
    $submitted = $world->submitted('Q1 2025');
    $draft = $world->draft('Q1 2026');
    $carry = $world->draft('Q4 2026');
    $submit = $world->draft('Q3 2026');
    $discard = $world->draft('Q2 2025');
    $intro = $world->section($draft, ReportWorld::INTRODUCTION);

    return [
        'list' => ['GET', '/api/v1/periodic-reports', []],
        'periods' => ['GET', '/api/v1/periodic-reports/periods', []],
        'create' => ['POST', '/api/v1/periodic-reports', ['reporting_period_label' => 'Q2 2026']],
        'show submitted' => ['GET', "/api/v1/periodic-reports/{$submitted->id}", []],
        'show draft' => ['GET', "/api/v1/periodic-reports/{$draft->id}", []],
        'save' => ['PATCH', "/api/v1/periodic-reports/{$draft->id}/sections/{$intro->id}", ['content' => 'Update']],
        'carry forward' => ['POST', "/api/v1/periodic-reports/{$carry->id}/carry-forward", []],
        'submit' => ['POST', "/api/v1/periodic-reports/{$submit->id}/submit", []],
        'discard' => ['DELETE', "/api/v1/periodic-reports/{$discard->id}", []],
        'compliance' => ['GET', '/api/v1/periodic-reports/compliance', []],
        'console compliance' => ['GET', '/api/v1/sdt/reports/compliance', []],
    ];
}

/**
 * @param  array<int, int>  $codes  in reportMatrixEndpoints() order
 * @return array<string, int>
 */
function reportExpectations(array $codes): array
{
    return array_combine(
        ['list', 'periods', 'create', 'show submitted', 'show draft', 'save', 'carry forward', 'submit', 'discard', 'compliance', 'console compliance'],
        $codes,
    );
}

it('applies the role x endpoint authorization matrix (TC-FR-RPT-017-M, TC-FR-RPT-018-M, TC-FR-AUTH-017-RPT)', function (string $roleName, array $expected) {
    $world = ReportWorld::create();
    $actor = $roleName === 'Ministry Attache (another mission)' ? $world->otherAttache : $world->member($roleName);

    foreach (reportMatrixEndpoints($world) as $endpoint => [$method, $uri, $body]) {
        $status = $this->actingAs($actor)->json($method, $uri, $body)->status();

        expect($status)->toBe($expected[$endpoint], "{$roleName} -> {$endpoint}");
    }
})->with([
    'Ministry Attache (own mission)' => ['Ministry Attache', reportExpectations([200, 200, 201, 200, 200, 200, 200, 200, 204, 403, 403])],
    'Ministry Attache (another mission)' => ['Ministry Attache (another mission)', reportExpectations([200, 200, 201, 403, 403, 403, 403, 403, 403, 403, 403])],
    'Ministry HQ Officer' => ['Ministry HQ Officer', reportExpectations([200, 200, 403, 200, 403, 403, 403, 403, 403, 403, 403])],
    'Ministry HQ Director' => ['Ministry HQ Director', reportExpectations([200, 200, 403, 200, 403, 403, 403, 403, 403, 200, 403])],
    'Ministry PS' => ['Ministry PS', reportExpectations([200, 200, 403, 200, 403, 403, 403, 403, 403, 200, 200])],
    'Acting PS' => ['Acting PS', reportExpectations([200, 200, 403, 200, 403, 403, 403, 403, 403, 200, 200])],
    'Ministry Publishing Authority' => ['Ministry Publishing Authority', reportExpectations([200, 200, 403, 200, 403, 403, 403, 403, 403, 403, 403])],
    'Head of Mission' => ['Head of Mission', reportExpectations([200, 200, 403, 200, 403, 403, 403, 403, 403, 403, 403])],
    'Deputy Head of Mission' => ['Deputy Head of Mission', reportExpectations([200, 200, 403, 200, 403, 403, 403, 403, 403, 403, 403])],
    'MFA HQ Officer' => ['MFA HQ Officer', reportExpectations([403, 403, 403, 403, 403, 403, 403, 403, 403, 403, 403])],
    'MFA Principal Secretary' => ['MFA Principal Secretary', reportExpectations([403, 403, 403, 403, 403, 403, 403, 403, 403, 403, 403])],
    'HRM&D Officer' => ['HRM&D Officer', reportExpectations([403, 403, 403, 403, 403, 403, 403, 403, 403, 403, 403])],
    'Ministry Administrator' => ['Ministry Administrator', reportExpectations([403, 403, 403, 403, 403, 403, 403, 403, 403, 403, 403])],
    'System Administrator' => ['System Administrator', reportExpectations([403, 403, 403, 403, 403, 403, 403, 403, 403, 403, 403])],
    'Designated Deputy' => ['Designated Deputy', reportExpectations([403, 403, 403, 403, 403, 403, 403, 403, 403, 403, 403])],
    'Honorary Consul' => ['Honorary Consul', reportExpectations([403, 403, 403, 403, 403, 403, 403, 403, 403, 403, 403])],
]);

it('lists a mission\'s own reports to its attache, drafts included, and nothing of another mission (TC-BR-001-RPT)', function () {
    $world = ReportWorld::create();
    $ownDraft = $world->draft('Q1 2026');
    $ownSubmitted = $world->submitted('Q4 2026');
    $world->submitted('Q4 2026', $world->otherAttache);
    $world->draft('Q1 2026', $world->foreignAttache);

    $ids = collect($this->actingAs($world->attache)->getJson('/api/v1/periodic-reports')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$ownDraft->id, $ownSubmitted->id]);

    // A mission_id filter can never widen an attache's view.
    $this->actingAs($world->attache)
        ->getJson("/api/v1/periodic-reports?mission_id={$world->otherMission->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('lists every mission\'s submitted reports to HQ, never a draft or another department\'s (TC-FR-RPT-017-A)', function () {
    $world = ReportWorld::create();
    $berlin = $world->submitted('Q4 2026');
    $accra = $world->submitted('Q4 2026', $world->otherAttache);
    $world->draft('Q1 2026');
    $world->submitted('Q4 2026', $world->foreignAttache);

    $response = $this->actingAs($world->officer)->getJson('/api/v1/periodic-reports')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())->toBe(collect([$berlin->id, $accra->id])->sort()->values()->all())
        ->and($response->json('meta.total'))->toBe(2);
});

it('filters the report list by mission, period, status, timeliness and text (TC-FR-RPT-017-B)', function () {
    $world = ReportWorld::create();
    $onTime = $world->submitted('Q4 2026');
    $late = $world->submitted('Q4 2026', $world->otherAttache, '2026-07-25 10:00:00');
    $older = $world->submitted('Q3 2026');

    $ids = fn (string $query): array => collect($this->actingAs($world->director)->getJson("/api/v1/periodic-reports?{$query}")->assertOk()->json('data'))
        ->pluck('id')->all();

    expect($ids("mission_id={$world->otherMission->id}"))->toBe([$late->id])
        ->and($ids('period='.rawurlencode('Q3 2026')))->toBe([$older->id])
        ->and($ids('timeliness=late'))->toBe([$late->id])
        ->and($ids('timeliness=on_time&period='.rawurlencode('Q4 2026')))->toBe([$onTime->id])
        ->and($ids('q=accra'))->toBe([$late->id])
        ->and($ids('q=ghana'))->toBe([$late->id])
        ->and($ids('q=amina'))->toBe([$onTime->id, $older->id])
        ->and($ids('status=draft'))->toBe([])
        ->and(count($ids('sort=mission')))->toBe(3)
        ->and($ids('sort=mission&period='.rawurlencode('Q4 2026')))->toBe([$late->id, $onTime->id]);
});

it('ignores invalid list parameters instead of failing (TC-FR-RPT-017-C)', function () {
    $world = ReportWorld::create();
    $world->submitted('Q4 2026');

    $this->actingAs($world->officer)
        ->getJson('/api/v1/periodic-reports?status[]=draft&mission_id=not-a-uuid&timeliness=sometimes&sort=nonsense&per_page=-4')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.per_page', 1);

    $this->actingAs($world->officer)->getJson('/api/v1/periodic-reports?per_page=5000')->assertOk()->assertJsonPath('meta.per_page', 100);
});

it('lists overdue drafts to their attache (TC-FR-RPT-016-D)', function () {
    $world = ReportWorld::create();
    $overdue = $world->draft('Q4 2026');
    $world->draft('Q1 2026');

    $this->actingAs($world->attache)
        ->getJson('/api/v1/periodic-reports?timeliness=overdue')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $overdue->id);
});

it('shows a Head of Mission their mission\'s submitted reports from every department (TC-FR-HOM-001-RPT)', function () {
    $world = ReportWorld::create();
    $headOfMission = $world->member('Head of Mission');

    $berlinReport = $world->submitted('Q4 2026');
    $world->submitted('Q4 2026', $world->otherAttache);
    $world->draft('Q1 2026');

    // Another department also has an attache at Berlin.
    $foreignAtBerlin = ReportWorld::user('Ministry Attache', $world->foreignMinistry, $world->mission, ['full_name' => 'Dana Foreign']);
    $foreignReport = $world->submitted('Q4 2026', $foreignAtBerlin);

    $response = $this->actingAs($headOfMission)->getJson('/api/v1/periodic-reports')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$berlinReport->id, $foreignReport->id])->sort()->values()->all());

    $this->actingAs($headOfMission)
        ->getJson("/api/v1/periodic-reports?ministry_id={$world->foreignMinistry->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.ministry.name', 'Foreign Department (test)');
});

it('never lets any role reach another department\'s report (TC-NFR-SEC-006-RPT)', function (string $roleName) {
    $world = ReportWorld::create();
    $foreign = $world->submitted('Q4 2026', $world->foreignAttache);
    $section = $world->section($foreign, ReportWorld::INTRODUCTION);
    $actor = $world->member($roleName);
    $base = "/api/v1/periodic-reports/{$foreign->id}";

    $this->actingAs($actor)->getJson($base)->assertNotFound();
    $this->actingAs($actor)->patchJson("{$base}/sections/{$section->id}", ['content' => 'x'])->assertNotFound();
    $this->actingAs($actor)->postJson("{$base}/carry-forward")->assertNotFound();
    $this->actingAs($actor)->postJson("{$base}/submit")->assertNotFound();

    // Deleting a report you cannot see is already the requested end state.
    $this->actingAs($actor)->deleteJson($base)->assertNoContent();

    expect(PeriodicReport::withoutGlobalScopes()->find($foreign->id))->not->toBeNull();
})->with(['Ministry Attache', 'Ministry HQ Officer', 'Ministry HQ Director', 'Ministry PS']);

it('returns allowed_actions that match what the API accepts for each viewer (TC-UI-006-RPT)', function () {
    $world = ReportWorld::create();
    $world->submitted('Q4 2026');
    $draft = $world->draft('Q1 2026');
    $submitted = $world->submitted('Q3 2026');
    $none = ['edit' => false, 'submit' => false, 'carry_forward' => false, 'discard' => false];

    expect($this->actingAs($world->attache)->getJson("/api/v1/periodic-reports/{$draft->id}")->json('data.allowed_actions'))
        ->toBe(['edit' => true, 'submit' => true, 'carry_forward' => true, 'discard' => true])
        ->and($this->actingAs($world->attache)->getJson("/api/v1/periodic-reports/{$submitted->id}")->json('data.allowed_actions'))->toBe($none)
        ->and($this->actingAs($world->ps)->getJson("/api/v1/periodic-reports/{$submitted->id}")->json('data.allowed_actions'))->toBe($none)
        ->and($this->actingAs($world->member('Head of Mission'))->getJson("/api/v1/periodic-reports/{$submitted->id}")->json('data.allowed_actions'))->toBe($none);
});

it('only lets an attache with a mission posting start a report (TC-FR-RPT-003-F)', function () {
    $world = ReportWorld::create();
    $unposted = ReportWorld::user('Ministry Attache', $world->ministry, null);

    $this->actingAs($unposted)->postJson('/api/v1/periodic-reports', ['reporting_period_label' => 'Q1 2026'])->assertForbidden();
    $this->actingAs($unposted)->getJson('/api/v1/periodic-reports')->assertOk()->assertJsonCount(0, 'data');
});

it('offers the recent periods with the attache\'s own report for each (TC-FR-RPT-003-G)', function () {
    $world = ReportWorld::create();
    $draft = $world->draft('Q1 2026');
    $late = $world->submitted('Q4 2026', null, '2026-07-20 12:00:00');

    $response = $this->actingAs($world->attache)->getJson('/api/v1/periodic-reports/periods')->assertOk();
    $periods = collect($response->json('data.periods'))->keyBy('label');

    expect($response->json('data.current.label'))->toBe('Q1 2026')
        ->and($response->json('data.can_create'))->toBeTrue()
        ->and($response->json('data.missions'))->toBe([['id' => $world->mission->id, 'name' => 'Berlin']])
        ->and($periods->keys()->take(3)->all())->toBe(['Q2 2026', 'Q1 2026', 'Q4 2026'])
        ->and($periods)->toHaveCount(ReportService::RECENT_PERIODS)
        ->and($periods['Q2 2026']['phase'])->toBe('in_progress')
        ->and($periods['Q2 2026']['report'])->toBeNull()
        ->and($periods['Q1 2026']['phase'])->toBe('open')
        ->and($periods['Q1 2026']['days_to_deadline'])->toBe(10)
        ->and($periods['Q1 2026']['report']['id'])->toBe($draft->id)
        ->and($periods['Q4 2026']['phase'])->toBe('closed')
        ->and($periods['Q4 2026']['report'])->toMatchArray(['id' => $late->id, 'status' => 'submitted', 'is_late' => true, 'days_overdue' => 5]);

    $hq = $this->actingAs($world->officer)->getJson('/api/v1/periodic-reports/periods')->assertOk();

    expect($hq->json('data.can_create'))->toBeFalse()
        ->and(collect($hq->json('data.missions'))->pluck('name')->all())->toBe(['Accra', 'Berlin', 'Lusaka', 'Zz Closed Post'])
        ->and($hq->json('data.periods.0'))->not->toHaveKey('report');
});

it('keeps report text out of search until submission and within each role\'s view (TC-FR-SEARCH-001-RPT)', function () {
    $world = ReportWorld::create();
    $service = app(ReportService::class);

    $draft = $world->draft('Q1 2026');
    $service->saveSectionContent($world->section($draft, ReportWorld::INTRODUCTION), 'Macadamia buyers visited the embassy.');

    $submitted = $world->draft('Q4 2026');
    $service->saveSectionContent($world->section($submitted, ReportWorld::INTRODUCTION), 'Avocado **exports** to Germany surged this quarter.');
    $service->submitReport($submitted, $world->attache, notify: false);

    $reportResults = fn (User $user, string $term): array => collect($this->actingAs($user)->getJson('/api/v1/search?q='.$term)->assertOk()->json('data'))
        ->where('type', 'periodic_report')
        ->values()
        ->all();

    $found = $reportResults($world->officer, 'avocado');

    expect($found)->toHaveCount(1)
        ->and($found[0]['id'])->toBe($submitted->id)
        ->and($found[0]['snippet'])->toContain('Avocado')
        ->and($found[0]['snippet'])->not->toContain('**')
        ->and($reportResults($world->attache, 'avocado'))->toHaveCount(1)
        ->and($reportResults($world->otherAttache, 'avocado'))->toBe([])
        ->and($reportResults($world->attache, 'macadamia'))->toBe([])
        ->and($reportResults($world->officer, 'macadamia'))->toBe([]);
});

it('lets the PS read the template versions but only administrators create one (TC-FR-RPT-001-A)', function () {
    $world = ReportWorld::create();
    $body = [
        'ministry_id' => $world->ministry->id,
        'effective_date' => '2026-10-10',
        'sections' => [['section_order' => 1, 'section_title' => 'Introduction', 'section_type' => 'narrative']],
    ];

    $this->actingAs($world->ps)->getJson('/api/v1/report-templates')->assertOk()->assertJsonPath('data.0.version', 1);
    $this->actingAs($world->member('Acting PS'))->getJson('/api/v1/report-templates')->assertOk();
    $this->actingAs($world->ps)->postJson('/api/v1/report-templates', $body)->assertForbidden();
    $this->actingAs($world->officer)->getJson('/api/v1/report-templates')->assertForbidden();
    $this->actingAs($world->director)->getJson('/api/v1/report-templates')->assertForbidden();
});

it('stores table behaviour and selection options on a new template version, leaving earlier reports untouched (TC-FR-RPT-001-B, BR-006)', function () {
    $world = ReportWorld::create();
    $existing = $world->draft('Q1 2026');
    $administrator = ReportWorld::user('Ministry Administrator', $world->ministry);

    $response = $this->actingAs($administrator)->postJson('/api/v1/report-templates', [
        'effective_date' => '2026-10-05',
        'sections' => [
            [
                'section_order' => 1,
                'section_title' => 'Budget',
                'section_type' => 'structured_table',
                'column_schema' => [
                    ['name' => 'Line', 'type' => 'text', 'mandatory' => true],
                    ['name' => 'Amount', 'type' => 'numeric', 'mandatory' => true],
                    ['name' => 'Channel', 'type' => 'selection', 'options' => ['Cash', 'Bank']],
                ],
                'table_config' => [
                    'total' => ['label' => 'TOTAL', 'label_column' => 'Line', 'sum_columns' => ['Amount']],
                    'max_rows' => 50,
                ],
            ],
        ],
    ])->assertCreated();

    expect($response->json('data.0.version'))->toBe(2)
        ->and($response->json('data.0.table_config.total.sum_columns'))->toBe(['Amount'])
        ->and($response->json('data.0.column_schema.2.options'))->toBe(['Cash', 'Bank']);

    $this->actingAs($world->attache)->getJson("/api/v1/periodic-reports/{$existing->id}")
        ->assertOk()
        ->assertJsonPath('data.template_version', 1)
        ->assertJsonCount(4, 'data.sections');

    $newReport = $this->actingAs($world->otherAttache)->postJson('/api/v1/periodic-reports', ['reporting_period_label' => 'Q1 2026'])->assertCreated();

    expect($newReport->json('data.template_version'))->toBe(2)
        ->and($newReport->json('data.sections.0.table.max_rows'))->toBe(50);

    $otherDepartment = Ministry::factory()->create();
    $this->actingAs($administrator)->postJson('/api/v1/report-templates', [
        'ministry_id' => $otherDepartment->id,
        'effective_date' => '2026-10-05',
        'sections' => [['section_order' => 1, 'section_title' => 'X', 'section_type' => 'narrative']],
    ])->assertStatus(422);
});
