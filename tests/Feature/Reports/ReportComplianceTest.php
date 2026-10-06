<?php

use App\Jobs\SendReportDeadlineReminder;
use App\Models\Notification;
use App\Services\ReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Reports\ReportWorld;

/**
 * FR-RPT-018 (compliance dashboard), FR-RPT-016 (lateness), FR-SDT-001 (the
 * PS console) and the report:send-reminders schedule: compliance is
 * computed from the reports themselves — nothing is cached or hand-set — so
 * every figure moves the moment a report is started or submitted.
 *
 * Pinned to 5 October 2026: Q1 2026 (July-September) is the period open for
 * submission, deadline 15 October.
 */
beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-05 09:00:00'));
});

it('shows each mission as submitted on time, submitted late, draft in progress or not started (TC-FR-RPT-018-A)', function () {
    $world = ReportWorld::create();
    $berlin = $world->submitted('Q1 2026', null, '2026-10-02 10:00:00');
    $accra = $world->draft('Q1 2026', $world->otherAttache);
    app(ReportService::class)->saveSectionContent($world->section($accra, ReportWorld::INTRODUCTION), 'Drafting.');

    $response = $this->actingAs($world->director)->getJson('/api/v1/periodic-reports/compliance')->assertOk();
    $rows = collect($response->json('data.missions'))->keyBy('mission_name');

    expect($rows->keys()->all())->toBe(['Accra', 'Berlin', 'Lusaka'])
        ->and($rows['Berlin'])->toMatchArray([
            'status' => 'submitted_on_time',
            'report_id' => $berlin->id,
            'is_late' => false,
            'is_overdue' => false,
            'days_overdue' => null,
            'progress' => null,
            'attache' => ['id' => $world->attache->id, 'full_name' => 'Amina Attache'],
        ])
        ->and($rows['Accra'])->toMatchArray([
            'status' => 'draft_in_progress',
            'report_id' => null,
            'is_overdue' => false,
            'progress' => ['total' => 4, 'complete' => 1, 'started' => 0, 'empty' => 3],
        ])
        ->and($rows['Lusaka'])->toMatchArray(['status' => 'not_started', 'attache' => null, 'report_id' => null])
        ->and($response->json('data.summary'))->toBe([
            'submitted_on_time' => 1,
            'submitted_late' => 0,
            'draft_in_progress' => 1,
            'not_started' => 1,
            'not_yet_submitted' => 2,
            'overdue' => 0,
            'vacant' => 1,
            'total' => 3,
        ])
        ->and($response->json('data.period'))->toMatchArray([
            'label' => 'Q1 2026',
            'deadline' => '2026-10-15',
            'phase' => 'open',
            'days_to_deadline' => 10,
        ]);
});

it('flags late submissions and overdue missions with the days past the deadline (TC-FR-RPT-018-B, TC-FR-RPT-016-E)', function () {
    $world = ReportWorld::create();
    $accra = $world->draft('Q1 2026', $world->otherAttache);

    $this->travelTo(Carbon::parse('2026-10-18 09:00:00'));
    $this->actingAs($world->otherAttache)->postJson("/api/v1/periodic-reports/{$accra->id}/submit")->assertOk();

    $response = $this->actingAs($world->ps)->getJson('/api/v1/periodic-reports/compliance?period_label='.rawurlencode('Q1 2026'))->assertOk();
    $rows = collect($response->json('data.missions'))->keyBy('mission_name');

    expect($rows['Accra'])->toMatchArray(['status' => 'submitted_late', 'is_late' => true, 'days_overdue' => 3, 'is_overdue' => false])
        ->and($rows['Berlin'])->toMatchArray(['status' => 'not_started', 'is_overdue' => true, 'days_overdue' => 3])
        ->and($response->json('data.summary.overdue'))->toBe(2)
        ->and($response->json('data.period.phase'))->toBe('closed');
});

it('includes an inactive mission only when it reported for the period (TC-FR-RPT-018-C, BR-003)', function () {
    $world = ReportWorld::create();

    $names = fn () => collect($this->actingAs($world->director)->getJson('/api/v1/periodic-reports/compliance')->json('data.missions'))->pluck('mission_name')->all();

    expect($names())->not->toContain('Zz Closed Post');

    $closedPostAttache = ReportWorld::user('Ministry Attache', $world->ministry, $world->closedMission);
    $world->submitted('Q1 2026', $closedPostAttache);

    expect($names())->toContain('Zz Closed Post');
});

it('defaults to the period open for submission and ignores a future or unknown one (TC-FR-RPT-018-D)', function () {
    $world = ReportWorld::create();

    $label = fn (string $query) => $this->actingAs($world->director)->getJson("/api/v1/periodic-reports/compliance{$query}")->assertOk()->json('data.period_label');

    expect($label(''))->toBe('Q1 2026')
        ->and($label('?period_label='.rawurlencode('Q4 2026')))->toBe('Q4 2026')
        ->and($label('?period_label='.rawurlencode('Q3 2027')))->toBe('Q1 2026')
        ->and($label('?period_label=last-quarter'))->toBe('Q1 2026')
        ->and($label('?period_label[]=Q4%202026'))->toBe('Q1 2026');

    $periods = $this->actingAs($world->director)->getJson('/api/v1/periodic-reports/compliance')->json('data.available_periods');

    expect(collect($periods)->pluck('label')->take(3)->all())->toBe(['Q2 2026', 'Q1 2026', 'Q4 2026'])
        ->and($periods)->toHaveCount(ReportService::RECENT_PERIODS)
        ->and($periods[0]['phase'])->toBe('in_progress');
});

it('builds each mission\'s compliance history over the recent periods (TC-FR-RPT-018-E)', function () {
    $world = ReportWorld::create();
    $onTime = $world->submitted('Q4 2026');
    $world->submitted('Q3 2026', null, '2026-04-20 09:00:00');
    $world->draft('Q4 2026', $world->otherAttache);

    $history = $this->actingAs($world->director)->getJson('/api/v1/periodic-reports/compliance')->assertOk()->json('data.history');

    expect(collect($history['periods'])->pluck('label')->all())->toBe(['Q4 2025', 'Q1 2025', 'Q2 2025', 'Q3 2026', 'Q4 2026', 'Q1 2026']);

    $berlin = collect($history['missions'])->firstWhere('mission_name', 'Berlin');
    $accra = collect($history['missions'])->firstWhere('mission_name', 'Accra');
    $berlinCells = collect($berlin['cells'])->keyBy('label');

    expect($berlinCells['Q4 2026'])->toBe(['label' => 'Q4 2026', 'status' => 'submitted_on_time', 'is_overdue' => false, 'report_id' => $onTime->id])
        ->and($berlinCells['Q3 2026']['status'])->toBe('submitted_late')
        ->and($berlinCells['Q1 2026'])->toMatchArray(['status' => 'not_started', 'is_overdue' => false])
        ->and($berlinCells['Q2 2025'])->toMatchArray(['status' => 'not_started', 'is_overdue' => true])
        ->and(collect($accra['cells'])->firstWhere('label', 'Q4 2026'))->toMatchArray(['status' => 'draft_in_progress', 'is_overdue' => true, 'report_id' => null])
        ->and(collect($history['totals'])->firstWhere('label', 'Q4 2026'))->toBe([
            'label' => 'Q4 2026',
            'submitted_on_time' => 1,
            'submitted_late' => 0,
            'draft_in_progress' => 1,
            'not_started' => 1,
        ]);
});

it('moves a mission from not started to draft to submitted as the attache works (TC-FR-RPT-018-F)', function () {
    $world = ReportWorld::create();
    $status = fn (): string => collect($this->actingAs($world->director)->getJson('/api/v1/periodic-reports/compliance')->json('data.missions'))
        ->firstWhere('mission_name', 'Berlin')['status'];

    expect($status())->toBe('not_started');

    $reportId = $this->actingAs($world->attache)->postJson('/api/v1/periodic-reports', ['reporting_period_label' => 'Q1 2026'])->json('data.id');
    expect($status())->toBe('draft_in_progress');

    $this->actingAs($world->attache)->postJson("/api/v1/periodic-reports/{$reportId}/submit")->assertOk();
    expect($status())->toBe('submitted_on_time');

    // Every compliance reader links straight to the submitted report.
    $this->actingAs($world->director)->getJson("/api/v1/periodic-reports/{$reportId}")->assertOk();
});

it('gives the PS console the same compliance board and a counts-only summary (TC-FR-SDT-001-RPT)', function () {
    $world = ReportWorld::create();
    $world->submitted('Q1 2026');
    $actingPs = $world->member('Acting PS');

    $board = $this->actingAs($world->ps)->getJson('/api/v1/sdt/reports/compliance')->assertOk();

    expect(array_keys($board->json('data')))->toBe(['period_label', 'period', 'missions', 'summary', 'available_periods', 'history'])
        ->and($board->json('data.summary.submitted_on_time'))->toBe(1);

    $this->actingAs($actingPs)->getJson('/api/v1/sdt/reports/compliance?period_label='.rawurlencode('Q4 2026'))
        ->assertOk()
        ->assertJsonPath('data.period_label', 'Q4 2026');

    $this->actingAs($world->ps)->getJson('/api/v1/sdt/reports/submission-summary')
        ->assertOk()
        ->assertJsonPath('data.period_label', 'Q1 2026')
        ->assertJsonPath('data.period.deadline', '2026-10-15')
        ->assertJsonPath('data.summary.not_yet_submitted', 2)
        ->assertJsonMissingPath('data.missions');
});

it('feeds the leadership dashboard the four-state compliance, most urgent first (TC-FR-SDT-001-DASH)', function () {
    $world = ReportWorld::create();
    $this->travelTo(Carbon::parse('2026-10-10 09:00:00'));
    $late = $world->submitted('Q1 2026', null, '2026-10-16 09:00:00');
    $world->draft('Q1 2026', $world->otherAttache);

    $reports = $this->actingAs($world->ps)->getJson('/api/v1/dashboard')->assertOk()->json('data.reports');

    expect($reports['summary'])->toMatchArray(['submitted_late' => 1, 'draft_in_progress' => 1, 'not_started' => 1])
        ->and(collect($reports['attention'])->pluck('status')->all())->toBe(['not_started', 'draft_in_progress', 'submitted_late'])
        ->and(collect($reports['attention'])->firstWhere('status', 'submitted_late')['report_id'])->toBe($late->id);
});

// --- Reminders and overdue notices (report:send-reminders) -------------------------

it('reminds each attache yet to submit seven and three days out, linking to their draft or the start page (TC-FR-RPT-016-REM-A)', function (string $today, int $daysLeft) {
    $world = ReportWorld::create();
    $draft = $world->draft('Q1 2026');

    $this->travelTo(Carbon::parse("{$today} 08:00:00"));
    $this->artisan('report:send-reminders')->assertSuccessful();

    $berlin = Notification::query()->where('recipient_user_id', $world->attache->id)->sole();
    $accra = Notification::query()->where('recipient_user_id', $world->otherAttache->id)->sole();

    // Each active department is worked out on its own: the second
    // department's attache is reminded about their own mission too.
    expect($berlin->trigger_type)->toBe('report_deadline_reminder')
        ->and($berlin->link)->toBe("/reports/{$draft->id}")
        ->and($berlin->message)->toContain("({$daysLeft} day(s) remaining)")
        ->and($accra->link)->toBe('/reports/new?period=Q1%202026')
        ->and(Notification::query()->where('recipient_user_id', $world->foreignAttache->id)->count())->toBe(1)
        ->and(Notification::query()->count())->toBe(3);

    Queue::assertPushed(SendReportDeadlineReminder::class, 3);
})->with([
    'seven days out' => ['2026-10-08', 7],
    'three days out' => ['2026-10-12', 3],
]);

it('reminds nobody whose mission has submitted, and nobody on other days (TC-FR-RPT-016-REM-B)', function () {
    $world = ReportWorld::create();
    $world->submitted('Q1 2026', null, '2026-10-06 09:00:00');

    $this->travelTo(Carbon::parse('2026-10-08 08:00:00'));
    $this->artisan('report:send-reminders')->assertSuccessful();

    expect(Notification::query()->where('recipient_user_id', $world->attache->id)->count())->toBe(0)
        ->and(Notification::query()->where('recipient_user_id', $world->otherAttache->id)->count())->toBe(1);

    $this->travelTo(Carbon::parse('2026-10-10 08:00:00'));
    $this->artisan('report:send-reminders')->assertSuccessful();

    expect(Notification::query()->where('recipient_user_id', '!=', $world->foreignAttache->id)->count())->toBe(1);
});

it('sends one overdue notice the day after the deadline (TC-FR-RPT-016-REM-C)', function () {
    $world = ReportWorld::create();
    $world->draft('Q1 2026');

    $this->travelTo(Carbon::parse('2026-10-16 08:00:00'));
    $this->artisan('report:send-reminders')->assertSuccessful();

    $notices = Notification::query()->where('trigger_type', 'report_overdue')->get();

    expect($notices->pluck('recipient_user_id')->sort()->values()->all())
        ->toBe(collect([$world->attache->id, $world->otherAttache->id, $world->foreignAttache->id])->sort()->values()->all())
        ->and($notices->first()->message)->toContain('deadline was Oct 15, 2026');

    Queue::assertPushed(SendReportDeadlineReminder::class, 3);

    $this->travelTo(Carbon::parse('2026-10-17 08:00:00'));
    $this->artisan('report:send-reminders')->assertSuccessful();

    expect(Notification::query()->where('trigger_type', 'report_overdue')->count())->toBe(3);
});
