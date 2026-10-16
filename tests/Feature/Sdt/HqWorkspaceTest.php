<?php

use App\Enums\AlertStatus;
use App\Enums\DirectiveStatus;
use App\Enums\InquiryStatus;
use App\Enums\PeriodicReportStatus;
use App\Models\Alert;
use App\Models\Directive;
use App\Models\Inquiry;
use App\Models\InquiryEvent;
use App\Models\PeriodicReport;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Sdt\HqWorkspaceWorld;

/**
 * FR-SDT-012 to 015 (TW-ARCH-001 L3-C-006): the HQ Trade Officer
 * workspace — who may open it, what each section lists, how the portfolio
 * narrows the department-wide views without ever hiding the officer's own
 * work, and how malformed or tampered requests fail closed.
 */
const HQ_WORKSPACE_ENDPOINTS = [
    '/api/v1/sdt/hq-workspace',
    '/api/v1/sdt/hq-workspace/alerts',
    '/api/v1/sdt/hq-workspace/inquiries',
    '/api/v1/sdt/hq-workspace/directives',
    '/api/v1/sdt/hq-workspace/reports',
];

beforeEach(function () {
    Queue::fake();
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(10, 0));
    $this->world = HqWorkspaceWorld::create();
});

/**
 * @return array<int, string>
 */
function workspaceIds(mixed $response): array
{
    return collect($response->json('data'))->pluck('id')->all();
}

// --- Access ---------------------------------------------------------------

it('opens every workspace section to a Ministry HQ Officer (TC-FR-SDT-012-A)', function () {
    foreach (HQ_WORKSPACE_ENDPOINTS as $endpoint) {
        $this->actingAs($this->world->officer)->getJson($endpoint)->assertOk();
    }
});

it('refuses every other role on every workspace section (TC-FR-SDT-012-B)', function (string $roleName) {
    $user = $this->world->member($roleName);

    foreach (HQ_WORKSPACE_ENDPOINTS as $endpoint) {
        $this->actingAs($user)->getJson($endpoint)->assertForbidden();
    }
})->with([
    'Ministry Attache', 'Ministry HQ Director', 'Ministry PS', 'Acting PS', 'Ministry Publishing Authority',
    'Designated Deputy', 'HRM&D Officer', 'Ministry Administrator', 'System Administrator',
    'Head of Mission', 'Deputy Head of Mission', 'MFA HQ Officer', 'MFA Principal Secretary',
]);

it('refuses an unauthenticated request and an HQ officer with no department (TC-FR-SDT-012-C)', function () {
    foreach (HQ_WORKSPACE_ENDPOINTS as $endpoint) {
        $this->getJson($endpoint)->assertUnauthorized();
    }

    $stray = HqWorkspaceWorld::user('Ministry HQ Officer', null);
    foreach (HQ_WORKSPACE_ENDPOINTS as $endpoint) {
        $this->actingAs($stray)->getJson($endpoint)->assertForbidden();
    }
});

it('authorises before it validates, so a refused role learns nothing from its filters (TC-FR-SDT-012-D)', function () {
    $this->actingAs($this->world->ps)
        ->getJson('/api/v1/sdt/hq-workspace/alerts?mission_id=not-a-uuid&queue=bogus&per_page=5000')
        ->assertForbidden();
});

it('rejects malformed and out-of-department filters with 422, never a server error (TC-FR-SDT-012-E)', function (string $query) {
    $this->actingAs($this->world->officer)->getJson("/api/v1/sdt/hq-workspace/alerts?{$query}")->assertUnprocessable();
})->with([
    'non-uuid mission' => 'mission_id=not-a-uuid',
    'unknown scope' => 'scope=everything',
    'unknown queue' => 'queue=all',
    'array scope' => 'scope[]=portfolio',
    'huge page size' => 'per_page=1000',
    'zero page' => 'page=0',
    'regex-like type' => 'intelligence_type=.*',
    'overlong search' => 'q='.str_repeat('a', 101),
]);

it('rejects a mission that is not posted to the officer\'s department (TC-FR-SDT-012-F)', function () {
    $foreign = HqWorkspaceWorld::linkedMission($this->world->mining, ['name' => 'Perth']);

    foreach (HQ_WORKSPACE_ENDPOINTS as $endpoint) {
        $this->actingAs($this->world->officer)->getJson("{$endpoint}?mission_id={$foreign->id}")->assertUnprocessable();
    }
});

it('rejects malformed section filters (TC-FR-SDT-012-G)', function (string $endpoint, string $query) {
    $this->actingAs($this->world->officer)->getJson("/api/v1/sdt/hq-workspace/{$endpoint}?{$query}")->assertUnprocessable();
})->with([
    ['inquiries', 'status=draft'],
    ['inquiries', 'high_value=maybe'],
    ['inquiries', 'sort=inquirer_email'],
    ['directives', 'queue=cancelled'],
    ['directives', 'sort=id;drop'],
    ['reports', 'period=2026-Q1'],
    ['reports', 'timeliness=early'],
]);

// --- Department isolation -------------------------------------------------

it('never lists another department\'s records, whatever the scope (TC-NFR-SEC-006-HQW)', function () {
    $world = $this->world;
    $miningAlert = Alert::factory()->create([
        'ministry_id' => $world->mining->id, 'mission_id' => $world->berlin->id, 'country' => 'Germany',
        'assigned_to_user_id' => $world->officer->id, 'status' => AlertStatus::Assigned->value,
    ]);
    $miningInquiry = Inquiry::factory()->create([
        'ministry_id' => $world->mining->id, 'mission_id' => $world->berlin->id, 'status' => InquiryStatus::Received->value,
    ]);
    InquiryEvent::factory()->create(['inquiry_id' => $miningInquiry->id, 'event_type' => 'hq_notified', 'logged_by_user_id' => $world->miningAttache->id]);
    $miningDirective = Directive::factory()->create([
        'ministry_id' => $world->mining->id, 'mission_id' => $world->berlin->id, 'target_user_id' => $world->miningAttache->id,
        'issued_by_user_id' => $world->officer->id, 'status' => DirectiveStatus::Issued->value, 'last_progress_update_at' => now(),
    ]);
    $miningReport = PeriodicReport::factory()->create([
        'ministry_id' => $world->mining->id, 'mission_id' => $world->berlin->id, 'status' => PeriodicReportStatus::Submitted->value,
        'reporting_period_label' => 'Q1 2026', 'period_start_date' => '2026-07-01', 'period_end_date' => '2026-09-30', 'submitted_at' => now(),
    ]);

    foreach (['portfolio', 'department'] as $scope) {
        foreach (['awaiting', 'feed'] as $queue) {
            expect(workspaceIds($this->actingAs($world->officer)->getJson("/api/v1/sdt/hq-workspace/alerts?scope={$scope}&queue={$queue}")))->not->toContain($miningAlert->id);
        }
        expect(workspaceIds($this->actingAs($world->officer)->getJson("/api/v1/sdt/hq-workspace/inquiries?scope={$scope}&status=all")))->not->toContain($miningInquiry->id)
            ->and(workspaceIds($this->actingAs($world->officer)->getJson("/api/v1/sdt/hq-workspace/directives?scope={$scope}&queue=all")))->not->toContain($miningDirective->id)
            ->and(workspaceIds($this->actingAs($world->officer)->getJson("/api/v1/sdt/hq-workspace/reports?scope={$scope}")))->not->toContain($miningReport->id);
    }

    $counts = $this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace?scope=department')->json('data.counts');
    expect($counts['alerts']['awaiting'])->toBe(0)
        ->and($counts['inquiries']['all'])->toBe(0)
        ->and($counts['directives']['all'])->toBe(0)
        ->and($counts['reports']['submitted'])->toBe(0);
});

// --- Portfolio and scope (FR-SDT-012) ---------------------------------------

it('defaults to the configured portfolio and expands to the whole department on request (TC-FR-SDT-012-H)', function () {
    $world = $this->world;
    $inBerlin = $world->alert();
    $teaInAccra = $world->alert(['mission_id' => $world->accra->id, 'country' => 'Ghana', 'sector' => 'Tea (re-export hub)']);
    $outside = $world->alert(['mission_id' => $world->london->id, 'country' => 'United Kingdom', 'sector' => 'cut flowers']);

    $overview = $this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace')->assertOk()->json('data');
    expect($overview['scope'])->toBe('portfolio')
        ->and($overview['portfolio']['configured'])->toBeTrue()
        ->and(collect($overview['portfolio']['missions'])->pluck('name')->all())->toBe(['Berlin'])
        ->and($overview['portfolio']['sectors'])->toBe(['tea'])
        ->and($overview['counts']['alerts']['feed'])->toBe(2);

    $portfolioFeed = $this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/alerts?queue=feed');
    expect($portfolioFeed->json('meta.scope'))->toBe('portfolio')
        ->and(workspaceIds($portfolioFeed))->toEqualCanonicalizing([$inBerlin->id, $teaInAccra->id]);

    $departmentFeed = $this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/alerts?queue=feed&scope=department');
    expect($departmentFeed->json('meta.scope'))->toBe('department')
        ->and(workspaceIds($departmentFeed))->toEqualCanonicalizing([$inBerlin->id, $teaInAccra->id, $outside->id])
        ->and(collect($departmentFeed->json('data'))->firstWhere('id', $outside->id)['in_portfolio'])->toBeFalse();
});

it('falls back to the whole department when no portfolio is configured (TC-FR-SDT-012-I)', function () {
    $world = $this->world;
    $world->alert(['mission_id' => $world->london->id]);

    $overview = $this->actingAs($world->otherOfficer)->getJson('/api/v1/sdt/hq-workspace?scope=portfolio')->assertOk()->json('data');
    $feed = $this->actingAs($world->otherOfficer)->getJson('/api/v1/sdt/hq-workspace/alerts?queue=feed&scope=portfolio');

    expect($overview['scope'])->toBe('department')
        ->and($overview['portfolio']['configured'])->toBeFalse()
        ->and($feed->json('meta.scope'))->toBe('department')
        ->and($feed->json('meta.total'))->toBe(1)
        ->and($feed->json('data.0.in_portfolio'))->toBeNull();
});

it('matches countries case-insensitively and sector keywords as whole words (TC-FR-SDT-012-J)', function () {
    $world = $this->world;
    $world->officer->forceFill(['portfolio' => ['mission_ids' => [], 'countries' => ['ghana'], 'sectors' => ['tea', 'a.b']]])->save();

    $ghana = $world->alert(['mission_id' => $world->london->id, 'country' => 'Ghana']);
    $teaPhrase = $world->alert(['mission_id' => $world->london->id, 'country' => 'United Kingdom', 'sector' => "tea (Kenya's largest market)"]);
    $teaCategory = $world->alert(['mission_id' => $world->london->id, 'country' => 'United Kingdom', 'product_category' => 'Black TEA']);
    $steam = $world->alert(['mission_id' => $world->london->id, 'country' => 'United Kingdom', 'sector' => 'steam turbines']);
    $regexBait = $world->alert(['mission_id' => $world->london->id, 'country' => 'United Kingdom', 'sector' => 'axb trading']);

    $ids = workspaceIds($this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/alerts?queue=feed'));

    expect($ids)->toEqualCanonicalizing([$ghana->id, $teaPhrase->id, $teaCategory->id])
        ->not->toContain($steam->id)
        ->not->toContain($regexBait->id);
});

it('narrows inquiries and reports by mission or host country, never by sector for reports (TC-FR-SDT-015-A)', function () {
    $world = $this->world;
    $world->officer->forceFill(['portfolio' => ['mission_ids' => [], 'countries' => ['Ghana'], 'sectors' => ['coffee']]])->save();

    $accraInquiry = $world->inquiry(['mission_id' => $world->accra->id]);
    $coffeeInquiry = $world->inquiry(['mission_id' => $world->london->id, 'product_or_sector' => 'Coffee']);
    $londonInquiry = $world->inquiry(['mission_id' => $world->london->id, 'product_or_sector' => 'Cut flowers']);
    $accraReport = $world->report(['mission_id' => $world->accra->id]);
    $londonReport = $world->report(['mission_id' => $world->london->id]);

    expect(workspaceIds($this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/inquiries')))->toEqualCanonicalizing([$accraInquiry->id, $coffeeInquiry->id])
        ->and(workspaceIds($this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/reports')))->toBe([$accraReport->id])
        ->and(workspaceIds($this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/reports?scope=department')))->toEqualCanonicalizing([$accraReport->id, $londonReport->id])
        ->and($londonInquiry->id)->not->toBeIn(workspaceIds($this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/inquiries')));
});

it('never hides the officer\'s own assigned alerts or issued directives behind the portfolio (TC-FR-SDT-012-K)', function () {
    $world = $this->world;
    $outsideAlert = $world->assignedAlert(null, ['mission_id' => $world->london->id, 'country' => 'United Kingdom']);
    $outsideDirective = $world->directive(['mission_id' => $world->accra->id, 'target_user_id' => $world->accraAttache->id]);

    $alerts = $this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/alerts?scope=portfolio')->json('data');
    $directives = $this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/directives?scope=portfolio&queue=open')->json('data');

    expect(collect($alerts)->pluck('id')->all())->toBe([$outsideAlert->id])
        ->and($alerts[0]['in_portfolio'])->toBeFalse()
        ->and(collect($directives)->pluck('id')->all())->toBe([$outsideDirective->id])
        ->and($directives[0]['in_portfolio'])->toBeFalse();
});

// --- Alerts (FR-SDT-013, FR-ALERT-009/010) ----------------------------------

it('lists alerts by queue and offers only the actions the API accepts (TC-FR-SDT-013-A)', function () {
    $world = $this->world;
    $awaiting = $world->assignedAlert();
    $acknowledged = $world->assignedAlert(null, ['status' => AlertStatus::Acknowledged->value]);
    $colleagues = $world->assignedAlert($world->otherOfficer);
    $routed = $world->alert();

    $awaitingRows = $this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/alerts')->json('data');
    $acknowledgedRows = $this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/alerts?queue=acknowledged')->json('data');
    $feed = collect($this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/alerts?queue=feed')->json('data'))->keyBy('id');

    expect(collect($awaitingRows)->pluck('id')->all())->toBe([$awaiting->id])
        ->and($awaitingRows[0]['actions'])->toBe(['acknowledge' => true, 'feedback' => true])
        ->and($awaitingRows[0]['assigned_to_me'])->toBeTrue()
        ->and(collect($acknowledgedRows)->pluck('id')->all())->toBe([$acknowledged->id])
        ->and($acknowledgedRows[0]['actions'])->toBe(['acknowledge' => false, 'feedback' => true])
        ->and($feed[$colleagues->id]['actions'])->toBe(['acknowledge' => false, 'feedback' => false])
        ->and($feed[$colleagues->id]['assigned_to']['full_name'])->toBe('David Officer')
        ->and($feed[$routed->id]['actions'])->toBe(['acknowledge' => false, 'feedback' => false]);
});

it('offers the active designated deputy the routed alerts\' actions (TC-FR-ALERT-008-HQW)', function () {
    $world = $this->world;
    $world->trade->forceFill(['designated_deputy_user_id' => $world->officer->id, 'designated_deputy_active' => true])->save();
    $routed = $world->alert();

    $row = collect($this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/alerts?queue=feed')->json('data'))->firstWhere('id', $routed->id);

    expect($row['actions'])->toBe(['acknowledge' => true, 'feedback' => true]);
});

it('searches alerts literally and filters them by intelligence type (TC-FR-SDT-013-B)', function () {
    $world = $this->world;
    $avocado = $world->assignedAlert(null, ['product_description' => 'Avocado import quota at 100% of 2025 volume', 'intelligence_type' => 'opportunities']);
    $world->assignedAlert(null, ['product_description' => 'Steel tariffs', 'intelligence_type' => 'trade_barriers']);

    expect(workspaceIds($this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/alerts?q=avocado')))->toBe([$avocado->id])
        ->and(workspaceIds($this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/alerts?q=100%25')))->toBe([$avocado->id])
        ->and(workspaceIds($this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/alerts?q=%25')))->toBe([$avocado->id])
        ->and(workspaceIds($this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/alerts?q=_')))->toBe([])
        ->and(workspaceIds($this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/alerts?intelligence_type=opportunities')))->toBe([$avocado->id]);
});

// --- Inquiries (FR-SDT-013, FR-INQ-009) --------------------------------------

it('lists only inquiries an attache notified HQ of, newest notification first (TC-FR-INQ-009-HQW)', function () {
    $world = $this->world;
    $older = $world->inquiry([], true, '2026-10-01 09:00:00');
    $newer = $world->inquiry([], true, '2026-10-12 09:00:00');
    $world->inquiry([], false);
    $world->inquiry(['status' => InquiryStatus::Draft->value]);

    $rows = $this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/inquiries')->json('data');

    expect(collect($rows)->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and($rows[0]['hq_notified']['by']['full_name'])->toBe('Amina Attache')
        ->and($rows[0]['hq_notified']['note'])->toBe('For HQ awareness.')
        ->and($rows[0])->not->toHaveKeys(['inquirer_email', 'inquirer_phone']);
});

it('groups notified inquiries by status and filters high-value ones (TC-FR-SDT-013-C)', function () {
    $world = $this->world;
    $open = $world->inquiry(['status' => InquiryStatus::InProgress->value, 'high_value_flag' => true]);
    $resolved = $world->inquiry(['status' => InquiryStatus::Resolved->value]);
    $closed = $world->inquiry(['status' => InquiryStatus::Closed->value]);
    $cancelled = $world->inquiry(['status' => InquiryStatus::Cancelled->value]);
    $draft = $world->inquiry(['status' => InquiryStatus::Draft->value]);

    $get = fn (string $query) => workspaceIds($this->actingAs($world->officer)->getJson("/api/v1/sdt/hq-workspace/inquiries?{$query}"));

    expect($get('status=open'))->toBe([$open->id])
        ->and($get('status=resolved'))->toBe([$resolved->id])
        ->and($get('status=closed'))->toEqualCanonicalizing([$closed->id, $cancelled->id])
        ->and($get('status=all'))->toEqualCanonicalizing([$open->id, $resolved->id, $closed->id, $cancelled->id, $draft->id])
        ->and($get('high_value=1'))->toBe([$open->id]);

    $counts = $this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace')->json('data.counts.inquiries');
    expect($counts)->toBe(['open' => 1, 'resolved' => 1, 'closed' => 2, 'all' => 5, 'high_value_open' => 1]);
});

// --- Directives (FR-SDT-014, FR-DIR-009 to 011) -------------------------------

it('lists the officer\'s directives that need attention, awaiting closure first (TC-FR-SDT-014-A)', function () {
    $world = $this->world;
    $stale = $world->directive(['status' => 'in_progress', 'last_progress_update_at' => now()->subDays(20)]);
    $overdue = $world->directive(['status' => 'acknowledged', 'target_completion_date' => '2026-10-01']);
    $awaitingClosure = $world->directive(['status' => 'completed', 'completion_summary' => 'Done.']);
    $healthy = $world->directive(['status' => 'issued', 'target_completion_date' => '2026-12-01']);
    $closed = $world->directive(['status' => 'closed']);
    $world->directive(['issued_by_user_id' => $world->otherOfficer->id, 'status' => 'completed']);

    $rows = $this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/directives')->json('data');

    expect(collect($rows)->pluck('id')->all())->toBe([$awaitingClosure->id, $overdue->id, $stale->id])
        ->and($rows[0]['attention'])->toBe(['awaiting_closure'])
        ->and($rows[0]['allowed_actions']['transitions'])->toBe(['closed'])
        ->and($rows[1]['attention'])->toBe(['overdue'])
        ->and($rows[1]['allowed_actions']['transitions'])->toBe(['cancelled'])
        ->and($rows[2]['attention'])->toBe(['stale'])
        ->and($rows[2]['allowed_actions']['add_note'])->toBeTrue();

    $get = fn (string $queue) => workspaceIds($this->actingAs($world->officer)->getJson("/api/v1/sdt/hq-workspace/directives?queue={$queue}"));
    expect($get('awaiting_closure'))->toBe([$awaitingClosure->id])
        ->and($get('open'))->toEqualCanonicalizing([$stale->id, $overdue->id, $healthy->id])
        ->and($get('all'))->toEqualCanonicalizing([$stale->id, $overdue->id, $awaitingClosure->id, $healthy->id, $closed->id]);

    $counts = $this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace')->json('data.counts.directives');
    expect($counts)->toBe(['attention' => 3, 'awaiting_closure' => 1, 'open' => 3, 'all' => 5, 'overdue' => 1, 'stale' => 1]);
});

// --- Reports (FR-SDT-015, FR-RPT-017) ---------------------------------------

it('lists submitted reports only, filterable by period and timeliness (TC-FR-SDT-015-B)', function () {
    $world = $this->world;
    $onTime = $world->report();
    $late = $world->report(['reporting_period_label' => 'Q4 2025', 'period_start_date' => '2026-04-01', 'period_end_date' => '2026-06-30', 'is_late' => true, 'submitted_at' => '2026-07-20 09:00:00']);
    $world->report(['reporting_period_label' => 'Q3 2025', 'period_start_date' => '2026-01-01', 'period_end_date' => '2026-03-31', 'status' => PeriodicReportStatus::Draft->value, 'submitted_at' => null]);

    $rows = $this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/reports')->json('data');

    expect(collect($rows)->pluck('id')->all())->toBe([$onTime->id, $late->id])
        ->and($rows[0]['mission']['host_country'])->toBe('Germany')
        ->and($rows[0]['authored_by']['full_name'])->toBe('Amina Attache')
        ->and(workspaceIds($this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/reports?period=Q4%202025')))->toBe([$late->id])
        ->and(workspaceIds($this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace/reports?timeliness=late')))->toBe([$late->id]);

    $overview = $this->actingAs($world->officer)->getJson('/api/v1/sdt/hq-workspace')->json('data');
    expect($overview['counts']['reports'])->toBe(['submitted' => 2, 'latest_period' => ['label' => 'Q1 2026', 'submitted' => 1, 'late' => 0]])
        ->and($overview['filter_options']['periods'])->toBe(['Q1 2026', 'Q4 2025']);
});

// --- Overview -----------------------------------------------------------------

it('keeps every overview count equal to its list total, with the mission filter applied (TC-FR-SDT-012-L)', function () {
    $world = $this->world;
    $world->assignedAlert();
    $world->assignedAlert(null, ['mission_id' => $world->accra->id, 'country' => 'Ghana']);
    $world->inquiry();
    $world->inquiry(['mission_id' => $world->accra->id]);
    $world->directive(['status' => 'completed']);
    $world->report();

    foreach (['', "mission_id={$world->accra->id}", 'scope=department'] as $query) {
        $counts = $this->actingAs($world->officer)->getJson("/api/v1/sdt/hq-workspace?{$query}")->assertOk()->json('data.counts');

        expect($counts['alerts']['awaiting'])->toBe($this->actingAs($world->officer)->getJson("/api/v1/sdt/hq-workspace/alerts?{$query}")->json('meta.total'))
            ->and($counts['alerts']['feed'])->toBe($this->actingAs($world->officer)->getJson("/api/v1/sdt/hq-workspace/alerts?queue=feed&{$query}")->json('meta.total'))
            ->and($counts['inquiries']['open'])->toBe($this->actingAs($world->officer)->getJson("/api/v1/sdt/hq-workspace/inquiries?{$query}")->json('meta.total'))
            ->and($counts['directives']['attention'])->toBe($this->actingAs($world->officer)->getJson("/api/v1/sdt/hq-workspace/directives?{$query}")->json('meta.total'))
            ->and($counts['reports']['submitted'])->toBe($this->actingAs($world->officer)->getJson("/api/v1/sdt/hq-workspace/reports?{$query}")->json('meta.total'));
    }
});

it('lists the department\'s missions as filter options, flagging the portfolio ones (TC-FR-SDT-012-M)', function () {
    $options = $this->actingAs($this->world->officer)->getJson('/api/v1/sdt/hq-workspace')->json('data.filter_options.missions');

    expect(collect($options)->pluck('name')->all())->toBe(['Accra', 'Berlin', 'London'])
        ->and(collect($options)->firstWhere('name', 'Berlin')['in_portfolio'])->toBeTrue()
        ->and(collect($options)->firstWhere('name', 'London')['in_portfolio'])->toBeFalse();
});
