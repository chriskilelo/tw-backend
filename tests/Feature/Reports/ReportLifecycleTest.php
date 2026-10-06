<?php

use App\Enums\PeriodicReportStatus;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\PeriodicReport;
use App\Models\ReportDataRow;
use App\Models\ReportSection;
use App\Services\ReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Reports\ReportWorld;

/**
 * URD Section 10.2 (FR-RPT-003 to 016): the report lifecycle end to end —
 * starting a report for a quarter, auto-saving narrative and table
 * sections, validation, completion, carry-forward, submission, lateness,
 * the post-submission lock and discarding a draft.
 *
 * Pinned to 5 October 2026: Q1 2026 (July-September) is open for
 * submission until its 15 October deadline and Q2 2026 is in progress.
 */
beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-05 09:00:00')));

function lifecycleSectionId(array $response, string $title): string
{
    return collect($response['data']['sections'])->firstWhere('section_title', $title)['id'];
}

// --- Starting a report (FR-RPT-003) ------------------------------------------

it('creates a draft for the quarter named by its label, with every template section and the mission, author and dates filled in (TC-FR-RPT-003-A)', function () {
    $world = ReportWorld::create();

    $response = $this->actingAs($world->attache)
        ->postJson('/api/v1/periodic-reports', ['reporting_period_label' => 'Q1 2026'])
        ->assertCreated();

    expect($response->json('data'))->toMatchArray([
        'reporting_period_label' => 'Q1 2026',
        'period_start_date' => '2026-07-01',
        'period_end_date' => '2026-09-30',
        'deadline' => '2026-10-15',
        'days_to_deadline' => 10,
        'status' => 'draft',
        'is_late' => false,
        'is_overdue' => false,
        'compliance_status' => 'draft_in_progress',
    ])
        ->and($response->json('data.mission.name'))->toBe('Berlin')
        ->and($response->json('data.authored_by.full_name'))->toBe('Amina Attache')
        ->and(collect($response->json('data.sections'))->pluck('section_title')->all())
        ->toBe([ReportWorld::INTRODUCTION, ReportWorld::ASSETS, ReportWorld::AIE, ReportWorld::CONCLUSION])
        ->and($response->json('data.allowed_actions'))->toBe(['edit' => true, 'submit' => true, 'carry_forward' => false, 'discard' => true]);
});

it('derives the period from its start date alone (TC-FR-RPT-003-B)', function () {
    $world = ReportWorld::create();

    $response = $this->actingAs($world->attache)
        ->postJson('/api/v1/periodic-reports', ['period_start_date' => '2026-04-01'])
        ->assertCreated();

    expect($response->json('data.reporting_period_label'))->toBe('Q4 2026')
        ->and($response->json('data.period_end_date'))->toBe('2026-06-30');
});

it('directs the attache to the existing report instead of creating a duplicate (TC-FR-RPT-003-C, BR-007)', function (bool $submitted) {
    $world = ReportWorld::create();
    $existing = $submitted ? $world->submitted('Q1 2026') : $world->draft('Q1 2026');

    $response = $this->actingAs($world->attache)
        ->postJson('/api/v1/periodic-reports', ['reporting_period_label' => 'Q1 2026'])
        ->assertStatus(422);

    expect($response->json('data.existing_report.id'))->toBe($existing->id)
        ->and($response->json('data.existing_report.status'))->toBe($submitted ? 'submitted' : 'draft')
        ->and($response->json('errors.0'))->toContain('already has a report for Q1 2026')
        ->and(PeriodicReport::withoutGlobalScopes()->count())->toBe(1);
})->with(['an existing draft' => false, 'a submitted report' => true]);

it('refuses a period that is not a reporting quarter or has not begun (TC-FR-RPT-003-D)', function (array $body, string $message) {
    $world = ReportWorld::create();

    $response = $this->actingAs($world->attache)->postJson('/api/v1/periodic-reports', $body)->assertStatus(422);

    expect(implode(' ', $response->json('errors')))->toContain($message)
        ->and(PeriodicReport::withoutGlobalScopes()->count())->toBe(0);
})->with([
    'free-typed label' => [['reporting_period_label' => 'Quarter one'], 'Choose a reporting period such as'],
    'mid-quarter start' => [['period_start_date' => '2026-07-15'], 'starts on 1 January, 1 April, 1 July or 1 October'],
    'label and start disagree' => [['reporting_period_label' => 'Q1 2026', 'period_start_date' => '2026-04-01'], 'Q1 2026 starts on 2026-07-01'],
    'wrong end date' => [['reporting_period_label' => 'Q1 2026', 'period_end_date' => '2026-09-29'], 'Q1 2026 ends on 2026-09-30'],
    'quarter not begun' => [['reporting_period_label' => 'Q3 2027'], 'Q3 2027 has not started yet'],
    'nothing named' => [[], 'reporting period label field is required'],
]);

it('lets an attache start a report they still owe for an earlier quarter, flagged overdue (TC-FR-RPT-003-E)', function () {
    $world = ReportWorld::create();

    $late = $this->actingAs($world->attache)
        ->postJson('/api/v1/periodic-reports', ['reporting_period_label' => 'Q4 2026'])
        ->assertCreated();

    $expectedDays = (int) Carbon::parse('2026-07-15')->diffInDays(Carbon::parse('2026-10-05'));

    expect($late->json('data.is_overdue'))->toBeTrue()
        ->and($late->json('data.days_overdue'))->toBe($expectedDays);
});

// --- Pre-populated rows (FR-RPT-008) -------------------------------------------

it('pre-populates the AIE table with the active budget codes, skipping the total stand-in and retired lines (TC-FR-RPT-008-A)', function () {
    $world = ReportWorld::create();

    $response = $this->actingAs($world->attache)
        ->postJson('/api/v1/periodic-reports', ['reporting_period_label' => 'Q1 2026'])
        ->assertCreated()
        ->json();

    $aie = collect($response['data']['sections'])->firstWhere('section_title', ReportWorld::AIE);
    $assets = collect($response['data']['sections'])->firstWhere('section_title', ReportWorld::ASSETS);

    expect(collect($aie['data_rows'])->pluck('row_data')->all())->toEqual([
        ['Budget Code' => '2110300', 'Head Description' => 'Personal Allowances-FSA'],
        ['Budget Code' => '2210100', 'Head Description' => 'Utilities: Electricity and Water'],
        ['Budget Code' => 'N/A', 'Head Description' => 'Bank Account Balance'],
    ])
        ->and($aie['table'])->toMatchArray([
            'label_columns' => ['Budget Code', 'Head Description'],
            'prepopulated' => true,
            'max_rows' => 200,
        ])
        ->and($aie['table']['total']['sum_columns'])->toBe(['Quarter Allocation', 'Deficit/Surplus'])
        ->and($aie['completion'])->toBe('empty')
        ->and($assets['data_rows'])->toBe([])
        ->and($assets['table']['prepopulated'])->toBeFalse();
});

// --- Narrative auto-save (FR-RPT-005, FR-RPT-006) ------------------------------------

it('persists every narrative auto-save, even two in quick succession (TC-FR-RPT-006-A)', function () {
    $world = ReportWorld::create();
    $report = $world->draft();
    $intro = $world->section($report, ReportWorld::INTRODUCTION);
    $uri = "/api/v1/periodic-reports/{$report->id}/sections/{$intro->id}";

    $this->actingAs($world->attache)->patchJson($uri, ['content' => 'Berlin opened'])->assertOk();
    $response = $this->actingAs($world->attache)->patchJson($uri, ['content' => 'Berlin opened two new buyer channels.'])->assertOk();

    expect(collect($response->json('data.sections'))->firstWhere('id', $intro->id)['content'])->toBe('Berlin opened two new buyer channels.')
        ->and($intro->fresh()->content)->toBe('Berlin opened two new buyer channels.');
});

it('treats an unchanged auto-save as a no-op (TC-FR-RPT-006-B)', function () {
    $world = ReportWorld::create();
    $report = $world->draft();
    $intro = $world->section($report, ReportWorld::INTRODUCTION);
    $uri = "/api/v1/periodic-reports/{$report->id}/sections/{$intro->id}";

    $this->actingAs($world->attache)->patchJson($uri, ['content' => 'Same text'])->assertOk();
    $savedAt = $intro->fresh()->updated_at;

    $this->travel(30)->seconds();
    $this->actingAs($world->attache)->patchJson($uri, ['content' => 'Same text'])->assertOk();

    expect($intro->fresh()->updated_at->equalTo($savedAt))->toBeTrue();
});

it('stores whitespace-only content as empty and refuses oversized content (TC-FR-RPT-006-C)', function () {
    $world = ReportWorld::create();
    $report = $world->draft();
    $intro = $world->section($report, ReportWorld::INTRODUCTION);
    $uri = "/api/v1/periodic-reports/{$report->id}/sections/{$intro->id}";

    $this->actingAs($world->attache)->patchJson($uri, ['content' => "   \n  "])->assertOk();
    expect($intro->fresh()->content)->toBeNull();

    $this->actingAs($world->attache)
        ->patchJson($uri, ['content' => str_repeat('a', ReportService::SECTION_CONTENT_MAX + 1)])
        ->assertStatus(422);
});

it('refuses a save that sends nothing, text for a table or rows for a narrative section (TC-FR-RPT-005)', function () {
    $world = ReportWorld::create();
    $report = $world->draft();
    $intro = $world->section($report, ReportWorld::INTRODUCTION);
    $assets = $world->section($report, ReportWorld::ASSETS);
    $base = "/api/v1/periodic-reports/{$report->id}/sections";

    $this->actingAs($world->attache)->patchJson("{$base}/{$intro->id}", [])->assertStatus(422);
    $this->actingAs($world->attache)->patchJson("{$base}/{$assets->id}", ['content' => 'prose'])->assertStatus(422);
    $this->actingAs($world->attache)->patchJson("{$base}/{$intro->id}", ['rows' => []])->assertStatus(422);
    $this->actingAs($world->attache)->patchJson("{$base}/{$intro->id}", ['content' => 'x', 'rows' => []])->assertStatus(422);
});

it('refuses a section of another report (TC-FR-RPT-005-B)', function () {
    $world = ReportWorld::create();
    $report = $world->draft('Q1 2026');
    $other = $world->draft('Q4 2026');
    $otherIntro = $world->section($other, ReportWorld::INTRODUCTION);

    $this->actingAs($world->attache)
        ->patchJson("/api/v1/periodic-reports/{$report->id}/sections/{$otherIntro->id}", ['content' => 'x'])
        ->assertNotFound();
});

// --- Structured tables (FR-RPT-007, FR-RPT-010, FR-RPT-012) ----------------------

it('adds, edits, reorders and removes table rows in one save, keeping each row id (TC-FR-RPT-007-A)', function () {
    $world = ReportWorld::create();
    $report = $world->draft();
    $assets = $world->section($report, ReportWorld::ASSETS);
    $uri = "/api/v1/periodic-reports/{$report->id}/sections/{$assets->id}";
    $clientId = (string) Str::uuid();

    $first = $this->actingAs($world->attache)->patchJson($uri, ['rows' => [
        ['row_data' => ['Item Number' => 1, 'Item Description' => 'Laptop', 'Status' => 'Serviceable']],
        ['id' => $clientId, 'row_data' => ['Item Number' => '2', 'Item Description' => ' Printer ', 'Status' => 'Needs Repair']],
    ]])->assertOk();

    $rows = collect($first->json('data.sections'))->firstWhere('id', $assets->id)['data_rows'];
    $laptopId = $rows[0]['id'];

    expect($rows)->toHaveCount(2)
        ->and($rows[1]['id'])->toBe($clientId)
        ->and($rows[1]['row_data'])->toEqual(['Item Number' => 2, 'Item Description' => 'Printer', 'Status' => 'Needs Repair'])
        ->and($rows[1]['row_data']['Item Number'])->toBe(2);

    // Printer first, the laptop edited, a scanner added.
    $second = $this->actingAs($world->attache)->patchJson($uri, ['rows' => [
        ['id' => $clientId, 'row_data' => ['Item Number' => 2, 'Item Description' => 'Printer', 'Status' => 'Needs Repair']],
        ['id' => $laptopId, 'row_data' => ['Item Number' => 1, 'Item Description' => 'Laptop', 'Status' => 'Disposed']],
        ['row_data' => ['Item Number' => 3, 'Item Description' => 'Scanner', 'Status' => 'Serviceable']],
    ]])->assertOk();

    $rows = collect($second->json('data.sections'))->firstWhere('id', $assets->id)['data_rows'];

    expect(collect($rows)->pluck('id')->take(2)->all())->toBe([$clientId, $laptopId])
        ->and(collect($rows)->pluck('row_data.Item Description')->all())->toBe(['Printer', 'Laptop', 'Scanner'])
        ->and($rows[1]['row_data']['Status'])->toBe('Disposed')
        ->and(collect($rows)->pluck('row_order')->all())->toBe([1, 2, 3]);

    // Only the scanner kept: the other two are removed.
    $this->actingAs($world->attache)->patchJson($uri, ['rows' => [$rows[2]]])->assertOk();

    expect(ReportDataRow::withoutGlobalScopes()->where('report_section_id', $assets->id)->pluck('id')->all())->toBe([$rows[2]['id']]);
});

it('refuses invalid cell values, naming the row and column, and saves nothing (TC-FR-RPT-010-A)', function (string $title, array $rowData, string $message) {
    $world = ReportWorld::create();
    $report = $world->draft();
    $section = $world->section($report, $title);
    $before = $section->dataRows->pluck('row_data')->all();

    $response = $this->actingAs($world->attache)
        ->patchJson("/api/v1/periodic-reports/{$report->id}/sections/{$section->id}", ['rows' => [['row_data' => $rowData]]])
        ->assertStatus(422);

    expect(implode(' ', $response->json('errors')))->toContain($message)
        ->and($section->fresh()->dataRows()->pluck('row_data')->all())->toEqual($before);
})->with([
    'text in a numeric column' => [ReportWorld::AIE, ['Budget Code' => '2110300', 'Quarter Allocation' => 'lots'], 'Row 1: Quarter Allocation must be a number'],
    'thousands separator' => [ReportWorld::AIE, ['Quarter Allocation' => '1,200'], 'Row 1: Quarter Allocation must be a number, without thousands separators'],
    'fraction in an integer column' => [ReportWorld::ASSETS, ['Item Number' => '2.5'], 'Row 1: Item Number must be a whole number'],
    'value outside the option list' => [ReportWorld::ASSETS, ['Status' => 'Lost'], 'Row 1: Status must be one of: Serviceable, Needs Repair, Disposed'],
    'unknown column' => [ReportWorld::ASSETS, ['Colour' => 'Red'], 'Unknown column key(s) for this section: Colour'],
    'nested value' => [ReportWorld::ASSETS, ['Remarks' => ['a', 'b']], 'Row 1: Remarks must be a single value'],
]);

it('stores numeric cells as numbers and keeps incomplete rows in a draft (TC-FR-RPT-010-B, TC-FR-RPT-012)', function () {
    $world = ReportWorld::create();
    $report = $world->draft();
    $aie = $world->section($report, ReportWorld::AIE);
    $rows = $aie->dataRows->sortBy('row_order')->values();

    $response = $this->actingAs($world->attache)->patchJson("/api/v1/periodic-reports/{$report->id}/sections/{$aie->id}", ['rows' => [
        ['id' => $rows[0]->id, 'row_data' => [...$rows[0]->row_data, 'Quarter Allocation' => '1500.50', 'Deficit/Surplus' => '-20']],
        ['id' => $rows[1]->id, 'row_data' => $rows[1]->row_data],
        ['id' => $rows[2]->id, 'row_data' => $rows[2]->row_data],
    ]])->assertOk();

    $saved = collect($response->json('data.sections'))->firstWhere('id', $aie->id);

    expect($saved['data_rows'][0]['row_data']['Quarter Allocation'])->toBe(1500.5)
        ->and($saved['data_rows'][0]['row_data']['Deficit/Surplus'])->toBe(-20)
        ->and($saved['completion'])->toBe('started');
});

it('refuses a row id that belongs to another table, and more rows than a table holds (TC-FR-RPT-007-B)', function () {
    $world = ReportWorld::create();
    $report = $world->draft();
    $otherReport = $world->draft('Q1 2026', $world->otherAttache);
    $assets = $world->section($report, ReportWorld::ASSETS);
    $foreignRowId = $world->section($otherReport, ReportWorld::AIE)->dataRows->first()->id;
    $uri = "/api/v1/periodic-reports/{$report->id}/sections/{$assets->id}";

    $this->actingAs($world->attache)
        ->patchJson($uri, ['rows' => [['id' => $foreignRowId, 'row_data' => ['Item Number' => 1]]]])
        ->assertStatus(422);

    expect(ReportDataRow::withoutGlobalScopes()->find($foreignRowId)->report_section_id)->not->toBe($assets->id);

    $tooMany = array_fill(0, 201, ['row_data' => ['Item Number' => 1]]);

    $this->actingAs($world->attache)->patchJson($uri, ['rows' => $tooMany])->assertStatus(422);
});

it('adds a single complete row, checking mandatory cells and types (TC-FR-RPT-007-C)', function () {
    $world = ReportWorld::create();
    $report = $world->draft();
    $assets = $world->section($report, ReportWorld::ASSETS);
    $uri = "/api/v1/periodic-reports/{$report->id}/data-rows";

    $this->actingAs($world->attache)->postJson($uri, [
        'section_id' => $assets->id,
        'row_data' => ['Item Number' => 1, 'Item Description' => 'Laptop', 'Status' => 'Serviceable'],
    ])->assertCreated();

    $this->actingAs($world->attache)->postJson($uri, [
        'section_id' => $assets->id,
        'row_data' => ['Item Number' => 2, 'Item Description' => 'Printer'],
    ])->assertStatus(422)->assertJsonFragment(['errors' => ['Missing mandatory column(s): Status (FR-RPT-007).']]);

    $this->actingAs($world->attache)->postJson($uri, [
        'section_id' => $assets->id,
        'row_data' => ['Item Number' => 'two', 'Item Description' => 'Printer', 'Status' => 'Serviceable'],
    ])->assertStatus(422);

    expect($assets->dataRows()->count())->toBe(1);
});

it('removes a table row idempotently (TC-FR-RPT-007-D)', function () {
    $world = ReportWorld::create();
    $report = $world->draft();
    $row = $world->section($report, ReportWorld::AIE)->dataRows->first();
    $uri = "/api/v1/periodic-reports/{$report->id}/data-rows/{$row->id}";

    $this->actingAs($world->attache)->deleteJson($uri)->assertOk();
    $this->actingAs($world->attache)->deleteJson($uri)->assertOk();

    expect(ReportDataRow::withoutGlobalScopes()->find($row->id))->toBeNull();
});

// --- Completion progress (FR-RPT-013) -----------------------------------------------------

it('reports each section as empty, started or complete, never counting pre-populated labels (TC-FR-RPT-013)', function () {
    $world = ReportWorld::create();
    $report = $world->draft();
    $sectionsUri = "/api/v1/periodic-reports/{$report->id}/sections";
    $intro = $world->section($report, ReportWorld::INTRODUCTION);
    $assets = $world->section($report, ReportWorld::ASSETS);
    $aie = $world->section($report, ReportWorld::AIE);

    $fresh = $this->actingAs($world->attache)->getJson("/api/v1/periodic-reports/{$report->id}")->assertOk();
    expect($fresh->json('data.progress'))->toBe(['total' => 4, 'complete' => 0, 'started' => 0, 'empty' => 4]);

    $this->actingAs($world->attache)->patchJson("{$sectionsUri}/{$intro->id}", ['content' => '## Overview'])->assertOk();
    $this->actingAs($world->attache)->patchJson("{$sectionsUri}/{$assets->id}", ['rows' => [
        ['row_data' => ['Item Number' => 1, 'Item Description' => 'Laptop']],
    ]])->assertOk();
    $aieRows = $aie->dataRows->sortBy('row_order')->values()
        ->map(fn (ReportDataRow $row) => ['id' => $row->id, 'row_data' => [...$row->row_data, 'Quarter Allocation' => 1000]])
        ->all();
    $response = $this->actingAs($world->attache)->patchJson("{$sectionsUri}/{$aie->id}", ['rows' => $aieRows])->assertOk();

    $states = collect($response->json('data.sections'))->pluck('completion', 'section_title')->all();

    expect($states)->toBe([
        ReportWorld::INTRODUCTION => 'complete',
        ReportWorld::ASSETS => 'started',
        ReportWorld::AIE => 'complete',
        ReportWorld::CONCLUSION => 'empty',
    ])
        ->and($response->json('data.progress'))->toBe(['total' => 4, 'complete' => 2, 'started' => 1, 'empty' => 1]);

    $list = $this->actingAs($world->attache)->getJson('/api/v1/periodic-reports')->assertOk();
    expect($list->json('data.0.progress'))->toBe(['total' => 4, 'complete' => 2, 'started' => 1, 'empty' => 1]);
});

// --- Carry forward (FR-RPT-011) -----------------------------------------------------------

it('carries a table forward from the most recent earlier submitted report, replacing its rows (TC-FR-RPT-011-A)', function () {
    $world = ReportWorld::create();
    $prior = $world->draft('Q4 2026');
    $service = app(ReportService::class);
    $service->saveSectionRows($world->section($prior, ReportWorld::ASSETS), [
        ['row_data' => ['Item Number' => 1, 'Item Description' => 'Laptop', 'Status' => 'Serviceable']],
        ['row_data' => ['Item Number' => 2, 'Item Description' => 'Printer', 'Status' => 'Needs Repair']],
    ]);
    $service->saveSectionContent($world->section($prior, ReportWorld::INTRODUCTION), 'Last quarter prose.');
    $this->travelTo(Carbon::parse('2026-07-10 09:00:00'));
    $service->submitReport($prior, $world->attache, notify: false);
    $this->travelTo(Carbon::parse('2026-10-05 09:00:00'));

    $report = $world->draft('Q1 2026');
    $assets = $world->section($report, ReportWorld::ASSETS);
    $uri = "/api/v1/periodic-reports/{$report->id}/carry-forward";

    $show = $this->actingAs($world->attache)->getJson("/api/v1/periodic-reports/{$report->id}")->assertOk();
    expect($show->json('data.allowed_actions.carry_forward'))->toBeTrue()
        ->and($show->json('data.carry_forward_source.reporting_period_label'))->toBe('Q4 2026');

    $this->actingAs($world->attache)->postJson($uri, ['section_id' => $assets->id])->assertOk();
    $response = $this->actingAs($world->attache)->postJson($uri, ['section_id' => $assets->id])->assertOk();

    $sections = collect($response->json('data.sections'))->keyBy('section_title');

    expect(collect($sections[ReportWorld::ASSETS]['data_rows'])->pluck('row_data.Item Description')->all())->toBe(['Laptop', 'Printer'])
        ->and($sections[ReportWorld::INTRODUCTION]['content'])->toBeNull()
        ->and($sections[ReportWorld::AIE]['data_rows'])->toHaveCount(3);
});

it('never carries forward from a later quarter, a draft or the total stand-in row (TC-FR-RPT-011-B)', function () {
    $world = ReportWorld::create();
    $service = app(ReportService::class);

    $older = $world->draft('Q3 2026');
    $later = $world->submitted('Q4 2026');

    $this->actingAs($world->attache)
        ->postJson("/api/v1/periodic-reports/{$older->id}/carry-forward")
        ->assertStatus(422)
        ->assertJsonFragment(['errors' => ['There is no earlier submitted report for this mission to carry forward from (FR-RPT-011).']]);

    // A later draft copies from Q4 2026, not from the older draft, and
    // leaves the TOTAL stand-in behind.
    $laterAie = $world->section($later, ReportWorld::AIE);
    DB::table('report_data_rows')->insert([
        'id' => (string) Str::uuid(),
        'report_section_id' => $laterAie->id,
        'row_order' => 9,
        'row_data' => json_encode(['Budget Code' => 'N/A', 'Head Description' => 'TOTAL (auto-calculated row)', 'Quarter Allocation' => 999]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $report = $world->draft('Q1 2026');
    $service->carryForward($report);

    $copied = $world->section($report, ReportWorld::AIE)->dataRows->pluck('row_data.Head Description')->all();

    expect($copied)->toBe(['Personal Allowances-FSA', 'Utilities: Electricity and Water', 'Bank Account Balance']);
});

it('refuses to carry forward a narrative section (TC-FR-RPT-011-C)', function () {
    $world = ReportWorld::create();
    $world->submitted('Q4 2026');
    $report = $world->draft('Q1 2026');
    $intro = $world->section($report, ReportWorld::INTRODUCTION);

    $this->actingAs($world->attache)
        ->postJson("/api/v1/periodic-reports/{$report->id}/carry-forward", ['section_id' => $intro->id])
        ->assertStatus(422);
});

// --- Submission, lateness and the lock (FR-RPT-014 to 016, BR-009, BR-010) -----------------

it('submits a draft regardless of completion, indexes it for search and notifies the attache (TC-FR-RPT-014-A)', function () {
    $world = ReportWorld::create();
    $report = $world->draft();
    app(ReportService::class)->saveSectionContent($world->section($report, ReportWorld::INTRODUCTION), 'Avocado exports surged in Berlin this quarter.');

    $response = $this->actingAs($world->attache)->postJson("/api/v1/periodic-reports/{$report->id}/submit")->assertOk();

    expect($response->json('data'))->toMatchArray([
        'status' => 'submitted',
        'is_late' => false,
        'days_overdue' => null,
        'compliance_status' => 'submitted_on_time',
        'allowed_actions' => ['edit' => false, 'submit' => false, 'carry_forward' => false, 'discard' => false],
    ])
        ->and($response->json('data.submitted_by.full_name'))->toBe('Amina Attache')
        ->and(DB::table('periodic_reports')->where('id', $report->id)->value('search_vector'))->not->toBeNull();

    $notification = Notification::query()->where('recipient_user_id', $world->attache->id)->where('trigger_type', 'report_submitted')->sole();
    expect($notification->link)->toBe("/reports/{$report->id}")
        ->and($notification->message)->toContain('Q1 2026 report was submitted on time');

    expect(AuditLog::query()->where('action', 'periodic_report.submitted')->where('affected_entity_id', $report->id)->count())->toBe(1);
});

it('flags a late submission with the number of days overdue (TC-FR-RPT-016-A, BR-010)', function () {
    $world = ReportWorld::create();
    $report = $world->draft();

    $this->travelTo(Carbon::parse('2026-10-18 10:00:00'));
    $response = $this->actingAs($world->attache)->postJson("/api/v1/periodic-reports/{$report->id}/submit")->assertOk();

    expect($response->json('data.is_late'))->toBeTrue()
        ->and($response->json('data.days_overdue'))->toBe(3)
        ->and($response->json('data.compliance_status'))->toBe('submitted_late')
        ->and(Notification::query()->where('trigger_type', 'report_submitted')->value('message'))->toContain('late, 3 day(s) after the deadline');
});

it('is on time up to the end of the deadline day (TC-FR-RPT-016-B)', function () {
    $world = ReportWorld::create();
    $report = $world->draft();

    $this->travelTo(Carbon::parse('2026-10-15 23:59:00'));

    expect($this->actingAs($world->attache)->postJson("/api/v1/periodic-reports/{$report->id}/submit")->json('data.is_late'))->toBeFalse();
});

it('flags a draft past its deadline as overdue (TC-FR-RPT-016-C)', function () {
    $world = ReportWorld::create();
    $report = $world->draft();

    $this->travelTo(Carbon::parse('2026-10-20 08:00:00'));
    $response = $this->actingAs($world->attache)->getJson("/api/v1/periodic-reports/{$report->id}")->assertOk();

    expect($response->json('data.is_overdue'))->toBeTrue()
        ->and($response->json('data.days_overdue'))->toBe(5)
        ->and($response->json('data.days_to_deadline'))->toBe(-5)
        ->and($response->json('data.compliance_status'))->toBe('draft_in_progress');
});

it('locks a submitted report against every change while it stays readable (TC-FR-RPT-015, BR-009)', function () {
    $world = ReportWorld::create();
    $report = $world->submitted();
    $intro = $world->section($report, ReportWorld::INTRODUCTION);
    $aie = $world->section($report, ReportWorld::AIE);
    $base = "/api/v1/periodic-reports/{$report->id}";

    $this->actingAs($world->attache)->patchJson("{$base}/sections/{$intro->id}", ['content' => 'Edited after submission'])->assertForbidden();
    $this->actingAs($world->attache)->postJson("{$base}/data-rows", ['section_id' => $aie->id, 'row_data' => ['Budget Code' => '1', 'Head Description' => 'x', 'Quarter Allocation' => 1]])->assertForbidden();
    $this->actingAs($world->attache)->deleteJson("{$base}/data-rows/{$aie->dataRows->first()->id}")->assertForbidden();
    $this->actingAs($world->attache)->postJson("{$base}/carry-forward")->assertForbidden();
    $this->actingAs($world->attache)->postJson("{$base}/submit")->assertForbidden();
    $this->actingAs($world->attache)->deleteJson($base)->assertForbidden();

    $this->actingAs($world->attache)->getJson($base)->assertOk();

    expect($intro->fresh()->content)->toBeNull()
        ->and($aie->dataRows()->count())->toBe(3)
        ->and($report->fresh()->status)->toBe(PeriodicReportStatus::Submitted);
});

it('never lets a save or a second submission land once the report is submitted, however they race (TC-BR-009-RACE)', function () {
    $world = ReportWorld::create();
    $report = $world->draft();
    $intro = $world->section($report, ReportWorld::INTRODUCTION);
    $assets = $world->section($report, ReportWorld::ASSETS);
    $service = app(ReportService::class);

    $service->submitReport($report, $world->attache, notify: false);

    expect(fn () => $service->saveSectionContent($intro, 'A late keystroke'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->saveSectionRows($assets, [['row_data' => ['Item Number' => 1]]]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->submitReport($report, $world->attache))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->discardDraft($report))->toThrow(InvalidArgumentException::class)
        ->and($intro->fresh()->content)->toBeNull()
        ->and(AuditLog::query()->where('action', 'periodic_report.submitted')->count())->toBe(1);
});

// --- Discarding a draft ---------------------------------------------------------------

it('discards a draft with its sections and rows, idempotently, and frees the period (TC-RPT-DISCARD)', function () {
    $world = ReportWorld::create();
    $report = $world->draft();
    $sectionIds = $report->sections->pluck('id');

    $this->actingAs($world->attache)->deleteJson("/api/v1/periodic-reports/{$report->id}")->assertNoContent();
    $this->actingAs($world->attache)->deleteJson("/api/v1/periodic-reports/{$report->id}")->assertNoContent();

    expect(PeriodicReport::withoutGlobalScopes()->find($report->id))->toBeNull()
        ->and(ReportSection::withoutGlobalScopes()->whereIn('id', $sectionIds)->count())->toBe(0)
        ->and(ReportDataRow::withoutGlobalScopes()->whereIn('report_section_id', $sectionIds)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'periodic_report.deleted')->where('affected_entity_id', $report->id)->exists())->toBeTrue();

    $this->actingAs($world->attache)->postJson('/api/v1/periodic-reports', ['reporting_period_label' => 'Q1 2026'])->assertCreated();
});

// --- Records that outlive their people (BR-002) ----------------------------------------------

it('keeps a report readable, with its author named, after the author is deactivated (TC-BR-002-RPT)', function () {
    $world = ReportWorld::create();
    $report = $world->submitted();
    $world->attache->delete();

    $this->actingAs($world->officer)->getJson('/api/v1/periodic-reports')
        ->assertOk()
        ->assertJsonPath('data.0.authored_by.full_name', 'Amina Attache');

    $this->actingAs($world->officer)->getJson("/api/v1/periodic-reports/{$report->id}")
        ->assertOk()
        ->assertJsonPath('data.submitted_by.full_name', 'Amina Attache');
});
