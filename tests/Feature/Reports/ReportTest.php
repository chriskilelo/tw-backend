<?php

use App\Enums\PeriodicReportStatus;
use App\Enums\SectionType;
use App\Models\Ministry;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\PeriodicReport;
use App\Models\ReportDataRow;
use App\Models\ReportSection;
use App\Models\ReportTemplateSection;
use App\Models\Role;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Support\Carbon;

/**
 * CLAUDE.md Section 11, 14 / API-001 (Periodic Report Engine). Session 25
 * covers template and draft management; this file also covers Session 26's
 * "second half" — submission, lateness, and compliance (FR-RPT-014, 016, 018,
 * BR-009).
 */
function reportRole(string $name, string $layer = '2', string $scope = 'mission'): Role
{
    return Role::query()->firstOrCreate(['name' => $name], ['layer' => $layer, 'scope' => $scope]);
}

function reportMinistryAttache(Ministry $ministry, Mission $mission): User
{
    return User::factory()->create([
        'role_id' => reportRole('Ministry Attache')->id,
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
    ]);
}

function reportSystemAdministrator(): User
{
    return User::factory()->create([
        'role_id' => reportRole('System Administrator', '1', 'platform')->id,
    ]);
}

function reportMinistryHqDirector(Ministry $ministry): User
{
    return User::factory()->create([
        'role_id' => reportRole('Ministry HQ Director', '2/3', 'ministry')->id,
        'ministry_id' => $ministry->id,
        'mission_id' => null,
    ]);
}

function reportMinistryPs(Ministry $ministry): User
{
    return User::factory()->create([
        'role_id' => reportRole('Ministry PS', '2/3', 'ministry')->id,
        'ministry_id' => $ministry->id,
        'mission_id' => null,
    ]);
}

/**
 * Seeds a minimal two-section template (one narrative, one structured_table
 * mirroring the Asset Register schema, CLAUDE.md Section 8) for a ministry.
 */
function seedReportTemplate(Ministry $ministry, int $version = 1, ?string $effectiveDate = null): void
{
    $effectiveDate ??= now()->subDay()->toDateString();

    ReportTemplateSection::factory()->create([
        'ministry_id' => $ministry->id,
        'version' => $version,
        'effective_date' => $effectiveDate,
        'section_order' => 1,
        'section_title' => 'Introduction',
        'section_type' => SectionType::Narrative->value,
        'column_schema' => null,
    ]);

    ReportTemplateSection::factory()->create([
        'ministry_id' => $ministry->id,
        'version' => $version,
        'effective_date' => $effectiveDate,
        'section_order' => 2,
        'section_title' => 'Asset Register of Trade Foreign Mission Office',
        'section_type' => SectionType::StructuredTable->value,
        'column_schema' => [
            ['name' => 'Item Number', 'type' => 'integer', 'mandatory' => true],
            ['name' => 'Item Description', 'type' => 'text', 'mandatory' => true],
        ],
    ]);
}

it('rejects creating a second draft report for the same mission and period (TC-FR-RPT-003)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = reportMinistryAttache($ministry, $mission);
    seedReportTemplate($ministry);

    $payload = [
        'reporting_period_label' => 'Q1 2027',
        'period_start_date' => '2027-07-01',
        'period_end_date' => '2027-09-30',
    ];

    $this->actingAs($attache)->postJson('/api/v1/periodic-reports', $payload)->assertCreated();

    $this->actingAs($attache)->postJson('/api/v1/periodic-reports', $payload)->assertStatus(422);

    expect(PeriodicReport::withoutGlobalScopes()->count())->toBe(1);
});

it("updates a narrative section's content and updated_at on auto-save (TC-FR-RPT-006)", function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = reportMinistryAttache($ministry, $mission);
    seedReportTemplate($ministry);

    $create = $this->actingAs($attache)->postJson('/api/v1/periodic-reports', [
        'reporting_period_label' => 'Q1 2027',
        'period_start_date' => '2027-07-01',
        'period_end_date' => '2027-09-30',
    ])->assertCreated();

    $reportId = $create->json('data.id');
    $narrativeSection = collect($create->json('data.sections'))->firstWhere('section_type', SectionType::Narrative->value);
    $originalUpdatedAt = $narrativeSection['updated_at'];

    $this->travel(10)->seconds();

    $response = $this->actingAs($attache)->patchJson(
        "/api/v1/periodic-reports/{$reportId}/sections/{$narrativeSection['id']}",
        ['content' => 'Updated narrative content.'],
    );

    $response->assertOk();

    $updatedSection = collect($response->json('data.sections'))->firstWhere('id', $narrativeSection['id']);
    expect($updatedSection['content'])->toBe('Updated narrative content.');
    expect($updatedSection['updated_at'])->not->toBe($originalUpdatedAt);

    $this->assertDatabaseHas('report_sections', [
        'id' => $narrativeSection['id'],
        'content' => 'Updated narrative content.',
    ]);
});

it('rejects a data row with an unknown column key (TC-FR-RPT-007)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = reportMinistryAttache($ministry, $mission);
    seedReportTemplate($ministry);

    $create = $this->actingAs($attache)->postJson('/api/v1/periodic-reports', [
        'reporting_period_label' => 'Q1 2027',
        'period_start_date' => '2027-07-01',
        'period_end_date' => '2027-09-30',
    ])->assertCreated();

    $reportId = $create->json('data.id');
    $tableSection = collect($create->json('data.sections'))->firstWhere('section_type', SectionType::StructuredTable->value);

    $response = $this->actingAs($attache)->postJson("/api/v1/periodic-reports/{$reportId}/data-rows", [
        'section_id' => $tableSection['id'],
        'row_data' => ['Not A Real Column' => 'value'],
    ]);

    $response->assertStatus(422);
    expect(ReportDataRow::withoutGlobalScopes()->count())->toBe(0);
});

it("copies the prior submitted report's structured table rows on carry-forward (TC-FR-RPT-011)", function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = reportMinistryAttache($ministry, $mission);
    seedReportTemplate($ministry);

    $priorReport = PeriodicReport::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'authored_by_user_id' => $attache->id,
        'reporting_period_label' => 'Q4 2026',
        'period_start_date' => '2026-04-01',
        'period_end_date' => '2026-06-30',
        'template_version' => 1,
        'status' => PeriodicReportStatus::Submitted->value,
        'submitted_at' => now(),
    ]);

    $priorTemplateSection = ReportTemplateSection::query()
        ->where('ministry_id', $ministry->id)
        ->where('section_type', SectionType::StructuredTable->value)
        ->firstOrFail();

    $priorSection = ReportSection::factory()->create([
        'periodic_report_id' => $priorReport->id,
        'report_template_section_id' => $priorTemplateSection->id,
        'content' => null,
    ]);

    ReportDataRow::factory()->create([
        'report_section_id' => $priorSection->id,
        'row_order' => 1,
        'row_data' => ['Item Number' => 1, 'Item Description' => 'Laptop'],
    ]);

    $create = $this->actingAs($attache)->postJson('/api/v1/periodic-reports', [
        'reporting_period_label' => 'Q1 2027',
        'period_start_date' => '2027-07-01',
        'period_end_date' => '2027-09-30',
    ])->assertCreated();

    $reportId = $create->json('data.id');

    $response = $this->actingAs($attache)->postJson("/api/v1/periodic-reports/{$reportId}/carry-forward");

    $response->assertOk();

    $tableSection = collect($response->json('data.sections'))->firstWhere('section_type', SectionType::StructuredTable->value);

    expect($tableSection['data_rows'])->toHaveCount(1);
    expect($tableSection['data_rows'][0]['row_data'])->toBe(['Item Number' => 1, 'Item Description' => 'Laptop']);
});

it('does not retroactively change an existing draft when a new template version is created (TC-BR-006)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = reportMinistryAttache($ministry, $mission);
    $admin = reportSystemAdministrator();
    seedReportTemplate($ministry, version: 1, effectiveDate: now()->subDays(2)->toDateString());

    $create = $this->actingAs($attache)->postJson('/api/v1/periodic-reports', [
        'reporting_period_label' => 'Q1 2027',
        'period_start_date' => '2027-07-01',
        'period_end_date' => '2027-09-30',
    ])->assertCreated();

    expect($create->json('data.template_version'))->toBe(1);

    $this->actingAs($admin)->postJson('/api/v1/report-templates', [
        'ministry_id' => $ministry->id,
        'effective_date' => now()->toDateString(),
        'sections' => [
            ['section_order' => 1, 'section_title' => 'Introduction (Revised)', 'section_type' => 'narrative'],
        ],
    ])->assertCreated();

    $reportId = $create->json('data.id');

    $show = $this->actingAs($attache)->getJson("/api/v1/periodic-reports/{$reportId}");

    $show->assertOk();
    expect($show->json('data.template_version'))->toBe(1);

    $sectionTitles = collect($show->json('data.sections'))->pluck('section_title')->all();
    expect($sectionTitles)->toContain('Introduction');
    expect($sectionTitles)->not->toContain('Introduction (Revised)');
});

it('does not alter a draft report created under the prior template version when a new version is created (TC-FR-RPT-002)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = reportMinistryAttache($ministry, $mission);
    seedReportTemplate($ministry, version: 1, effectiveDate: now()->subDays(2)->toDateString());

    $create = $this->actingAs($attache)->postJson('/api/v1/periodic-reports', [
        'reporting_period_label' => 'Q1 2027',
        'period_start_date' => '2027-07-01',
        'period_end_date' => '2027-09-30',
    ])->assertCreated();

    $reportId = $create->json('data.id');
    $originalSectionIds = collect($create->json('data.sections'))->pluck('id')->sort()->values()->all();
    $v1TemplateSectionIds = ReportTemplateSection::query()
        ->where('ministry_id', $ministry->id)->where('version', 1)
        ->pluck('id')->sort()->values()->all();

    app(ReportService::class)->createTemplateVersion($ministry->id, now(), [
        ['section_order' => 1, 'section_title' => 'Introduction (Revised)', 'section_type' => 'narrative'],
    ]);

    // The prior version's own rows are untouched (BR-006: additive, never overwritten).
    $unchangedV1Ids = ReportTemplateSection::query()
        ->where('ministry_id', $ministry->id)->where('version', 1)
        ->pluck('id')->sort()->values()->all();
    expect($unchangedV1Ids)->toBe($v1TemplateSectionIds);
    expect(ReportTemplateSection::query()->where('ministry_id', $ministry->id)->where('version', 2)->count())->toBe(1);

    // The draft itself: still pinned to template_version 1, and its
    // report_sections still reference the original v1 template rows.
    $report = PeriodicReport::query()->findOrFail($reportId);
    expect($report->template_version)->toBe(1);

    $sectionIds = ReportSection::query()->where('periodic_report_id', $reportId)->pluck('id')->sort()->values()->all();
    expect($sectionIds)->toBe($originalSectionIds);

    $reportTemplateSectionIds = ReportSection::query()
        ->where('periodic_report_id', $reportId)
        ->pluck('report_template_section_id')->sort()->values()->all();
    expect($reportTemplateSectionIds)->toBe($v1TemplateSectionIds);
});

// --- Session 26: FR-RPT-014, 016, 018, BR-009 ---------------------------

it('sets status to submitted and records an audit_log entry on submit (TC-FR-RPT-014)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = reportMinistryAttache($ministry, $mission);
    seedReportTemplate($ministry);

    $create = $this->actingAs($attache)->postJson('/api/v1/periodic-reports', [
        'reporting_period_label' => 'Q1 2027',
        'period_start_date' => '2027-07-01',
        'period_end_date' => '2027-09-30',
    ])->assertCreated();

    $reportId = $create->json('data.id');

    // Deadline for Q1 2027 (Jul-Sep) is 15 Oct 2027 (CLAUDE.md Section 8);
    // submitting on the 5th is well within the window.
    $this->travelTo(Carbon::parse('2027-10-05 09:00:00'));

    $response = $this->actingAs($attache)->postJson("/api/v1/periodic-reports/{$reportId}/submit");

    $response->assertOk();
    expect($response->json('data.status'))->toBe(PeriodicReportStatus::Submitted->value)
        ->and($response->json('data.submitted_at'))->not->toBeNull()
        ->and($response->json('data.is_late'))->toBeFalse();

    $this->assertDatabaseHas('periodic_reports', [
        'id' => $reportId,
        'status' => PeriodicReportStatus::Submitted->value,
        'is_late' => false,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $attache->id,
        'action' => 'periodic_report.submitted',
        'affected_entity_type' => 'periodic_report',
        'affected_entity_id' => $reportId,
    ]);
});

it('flags a report late when submitted after the deadline (TC-FR-RPT-016)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = reportMinistryAttache($ministry, $mission);
    seedReportTemplate($ministry);

    $create = $this->actingAs($attache)->postJson('/api/v1/periodic-reports', [
        'reporting_period_label' => 'Q1 2027',
        'period_start_date' => '2027-07-01',
        'period_end_date' => '2027-09-30',
    ])->assertCreated();

    $reportId = $create->json('data.id');

    // Deadline for Q1 2027 (Jul-Sep) is 15 Oct 2027; submitting on the 16th
    // is one day past it.
    $this->travelTo(Carbon::parse('2027-10-16 09:00:00'));

    $response = $this->actingAs($attache)->postJson("/api/v1/periodic-reports/{$reportId}/submit");

    $response->assertOk();
    expect($response->json('data.is_late'))->toBeTrue();

    $this->assertDatabaseHas('periodic_reports', [
        'id' => $reportId,
        'is_late' => true,
    ]);
});

it('allows submission with incomplete sections and no data rows, no completion gate (TC-BR-009)', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = reportMinistryAttache($ministry, $mission);
    seedReportTemplate($ministry);

    $create = $this->actingAs($attache)->postJson('/api/v1/periodic-reports', [
        'reporting_period_label' => 'Q1 2027',
        'period_start_date' => '2027-07-01',
        'period_end_date' => '2027-09-30',
    ])->assertCreated();

    // No section content or data rows were ever added — every narrative
    // section's content is still null, the structured table is empty.
    $sections = collect($create->json('data.sections'));
    expect($sections->pluck('content')->filter()->isEmpty())->toBeTrue();

    $reportId = $create->json('data.id');

    $response = $this->actingAs($attache)->postJson("/api/v1/periodic-reports/{$reportId}/submit");

    $response->assertOk();
    expect($response->json('data.status'))->toBe(PeriodicReportStatus::Submitted->value);
});

it('rejects submitting a report belonging to another mission', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $otherMission = Mission::factory()->create();
    $attache = reportMinistryAttache($ministry, $mission);
    $otherAttache = reportMinistryAttache($ministry, $otherMission);
    seedReportTemplate($ministry);

    $create = $this->actingAs($attache)->postJson('/api/v1/periodic-reports', [
        'reporting_period_label' => 'Q1 2027',
        'period_start_date' => '2027-07-01',
        'period_end_date' => '2027-09-30',
    ])->assertCreated();

    $this->actingAs($otherAttache)
        ->postJson("/api/v1/periodic-reports/{$create->json('data.id')}/submit")
        ->assertForbidden();
});

it('rejects submitting an already-submitted report', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = reportMinistryAttache($ministry, $mission);
    seedReportTemplate($ministry);

    $create = $this->actingAs($attache)->postJson('/api/v1/periodic-reports', [
        'reporting_period_label' => 'Q1 2027',
        'period_start_date' => '2027-07-01',
        'period_end_date' => '2027-09-30',
    ])->assertCreated();

    $reportId = $create->json('data.id');

    $this->actingAs($attache)->postJson("/api/v1/periodic-reports/{$reportId}/submit")->assertOk();
    $this->actingAs($attache)->postJson("/api/v1/periodic-reports/{$reportId}/submit")->assertForbidden();
});

it('shows submitted, late, and not-yet-submitted missions on the compliance dashboard (TC-FR-RPT-018)', function () {
    $ministry = Ministry::factory()->create();
    $missionOnTime = Mission::factory()->create();
    $missionLate = Mission::factory()->create();
    $missionPending = Mission::factory()->create();
    $attacheOnTime = reportMinistryAttache($ministry, $missionOnTime);
    $attacheLate = reportMinistryAttache($ministry, $missionLate);
    $attachePending = reportMinistryAttache($ministry, $missionPending);
    $director = reportMinistryHqDirector($ministry);
    seedReportTemplate($ministry);

    foreach ([$missionOnTime, $missionLate, $missionPending] as $mission) {
        MissionMinistryLink::factory()->create([
            'ministry_id' => $ministry->id,
            'mission_id' => $mission->id,
            'active_attache_user_id' => match ($mission->id) {
                $missionOnTime->id => $attacheOnTime->id,
                $missionLate->id => $attacheLate->id,
                default => $attachePending->id,
            },
        ]);
    }

    $this->travelTo(Carbon::parse('2027-10-05 09:00:00'));

    $onTimeReport = $this->actingAs($attacheOnTime)->postJson('/api/v1/periodic-reports', [
        'reporting_period_label' => 'Q1 2027',
        'period_start_date' => '2027-07-01',
        'period_end_date' => '2027-09-30',
    ])->assertCreated();
    $this->actingAs($attacheOnTime)->postJson("/api/v1/periodic-reports/{$onTimeReport->json('data.id')}/submit")->assertOk();

    $lateReport = $this->actingAs($attacheLate)->postJson('/api/v1/periodic-reports', [
        'reporting_period_label' => 'Q1 2027',
        'period_start_date' => '2027-07-01',
        'period_end_date' => '2027-09-30',
    ])->assertCreated();

    $this->travelTo(Carbon::parse('2027-10-16 09:00:00'));
    $this->actingAs($attacheLate)->postJson("/api/v1/periodic-reports/{$lateReport->json('data.id')}/submit")->assertOk();

    // $missionPending never submits anything for Q1 2027.

    $response = $this->actingAs($director)->getJson('/api/v1/periodic-reports/compliance?'.http_build_query(['period_label' => 'Q1 2027']));

    $response->assertOk();

    $rows = collect($response->json('data.missions'))->keyBy('mission_id');

    expect($rows[$missionOnTime->id]['status'])->toBe('submitted_on_time')
        ->and($rows[$missionLate->id]['status'])->toBe('submitted_late')
        ->and($rows[$missionPending->id]['status'])->toBe('not_yet_submitted')
        ->and($response->json('data.summary'))->toBe([
            'submitted_on_time' => 1,
            'submitted_late' => 1,
            'not_yet_submitted' => 1,
        ]);
});

it('rejects a Ministry Attache from the compliance dashboard', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = reportMinistryAttache($ministry, $mission);

    $this->actingAs($attache)
        ->getJson('/api/v1/periodic-reports/compliance')
        ->assertForbidden();
});

it('sends a report_deadline_reminder notification 7 days before the deadline to missions with no submitted report', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = reportMinistryAttache($ministry, $mission);

    MissionMinistryLink::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'active_attache_user_id' => $attache->id,
    ]);

    // Deadline for Q1 2027 (Jul-Sep) is 15 Oct 2027; 7 days before is Oct 8.
    $this->travelTo(Carbon::parse('2027-10-08 08:00:00'));

    $this->artisan('report:send-reminders')->assertSuccessful();

    $this->assertDatabaseHas('notifications', [
        'recipient_user_id' => $attache->id,
        'trigger_type' => 'report_deadline_reminder',
    ]);
});

it('sends no reminder outside the configured 7/3-day lead times', function () {
    $ministry = Ministry::factory()->create();
    $mission = Mission::factory()->create();
    $attache = reportMinistryAttache($ministry, $mission);

    MissionMinistryLink::factory()->create([
        'ministry_id' => $ministry->id,
        'mission_id' => $mission->id,
        'active_attache_user_id' => $attache->id,
    ]);

    $this->travelTo(Carbon::parse('2027-10-01 08:00:00'));

    $this->artisan('report:send-reminders')->assertSuccessful();

    $this->assertDatabaseMissing('notifications', [
        'recipient_user_id' => $attache->id,
        'trigger_type' => 'report_deadline_reminder',
    ]);
});

it('responds within 1500ms for the periodic reports list with 17 missions x 4 quarters seeded (TC-NFR-PERF-001)', function () {
    $ministry = Ministry::factory()->create();
    $director = reportMinistryHqDirector($ministry);

    $quarters = [
        ['label' => 'Q1 2027', 'start' => '2027-07-01', 'end' => '2027-09-30'],
        ['label' => 'Q2 2027', 'start' => '2027-10-01', 'end' => '2027-12-31'],
        ['label' => 'Q3 2028', 'start' => '2028-01-01', 'end' => '2028-03-31'],
        ['label' => 'Q4 2028', 'start' => '2028-04-01', 'end' => '2028-06-30'],
    ];

    foreach (Mission::factory()->count(17)->create() as $mission) {
        foreach ($quarters as $quarter) {
            PeriodicReport::factory()->create([
                'ministry_id' => $ministry->id,
                'mission_id' => $mission->id,
                'reporting_period_label' => $quarter['label'],
                'period_start_date' => $quarter['start'],
                'period_end_date' => $quarter['end'],
            ]);
        }
    }

    $start = microtime(true);

    $response = $this->actingAs($director)->getJson('/api/v1/periodic-reports?per_page=100');

    $elapsedMs = (microtime(true) - $start) * 1000;

    $response->assertOk();
    expect($response->json('meta.total'))->toBe(68);
    expect($elapsedMs)->toBeLessThan(1500);
});
