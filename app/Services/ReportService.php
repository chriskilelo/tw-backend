<?php

namespace App\Services;

use App\Enums\PeriodicReportStatus;
use App\Enums\SectionType;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\PeriodicReport;
use App\Models\ReportDataRow;
use App\Models\ReportSection;
use App\Models\ReportTemplateSection;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * CLAUDE.md Section 11 / FR-RPT-002 to 011: business logic for the Periodic
 * Report Engine. Reports\ReportTemplateController and
 * Reports\PeriodicReportController stay thin and delegate every mutation
 * here.
 *
 * Report templates are versioned and never overwritten (BR-006): a new
 * version is always a new set of report_template_sections rows, never an
 * edit of an existing row. createDraftReport() snapshots the version active
 * at creation time onto periodic_reports.template_version so a later
 * template edit can never retroactively change an existing draft.
 */
class ReportService
{
    public function __construct(private readonly AuditService $auditService) {}

    /**
     * FR-RPT-002: the sections of the template version currently in effect
     * for a ministry (the highest version whose effective_date has passed),
     * ordered for rendering.
     */
    public function getActiveTemplate(string $ministryId): Collection
    {
        $version = $this->resolveActiveVersion($ministryId);

        return ReportTemplateSection::query()
            ->where('ministry_id', $ministryId)
            ->where('version', $version)
            ->orderBy('section_order')
            ->get();
    }

    /**
     * BR-006: a new template version is always additive rows, never an edit
     * of an existing version's rows.
     *
     * @param  array<int, array{section_order:int, section_title:string, section_type:string, column_schema?: array<int, array{name:string,type:string,mandatory?:bool}>|null, guidance_text?: ?string}>  $sections
     */
    public function createTemplateVersion(string $ministryId, Carbon $effectiveDate, array $sections): Collection
    {
        $nextVersion = (int) (ReportTemplateSection::query()->where('ministry_id', $ministryId)->max('version') ?? 0) + 1;

        return DB::transaction(function () use ($ministryId, $effectiveDate, $sections, $nextVersion): Collection {
            return collect($sections)->map(fn (array $section) => ReportTemplateSection::create([
                'ministry_id' => $ministryId,
                'version' => $nextVersion,
                'effective_date' => $effectiveDate,
                'section_order' => $section['section_order'],
                'section_title' => $section['section_title'],
                'section_type' => $section['section_type'],
                'column_schema' => $section['column_schema'] ?? null,
                'guidance_text' => $section['guidance_text'] ?? null,
            ]));
        });
    }

    /**
     * BR-007: only one draft report instance may exist per mission per
     * reporting period, enforced by the database unique index on
     * (mission_id, ministry_id, period_start_date, period_end_date) —
     * caught here, not pre-checked in application code, so a race between
     * two concurrent requests can never slip through.
     *
     * @throws InvalidArgumentException If a draft already exists for this
     *                                  mission and period (BR-007).
     */
    public function createDraftReport(User $attache, string $periodLabel, Carbon $start, Carbon $end): PeriodicReport
    {
        $version = $this->resolveActiveVersion($attache->ministry_id);

        try {
            return DB::transaction(function () use ($attache, $periodLabel, $start, $end, $version): PeriodicReport {
                $report = PeriodicReport::create([
                    'ministry_id' => $attache->ministry_id,
                    'mission_id' => $attache->mission_id,
                    'authored_by_user_id' => $attache->id,
                    'reporting_period_label' => $periodLabel,
                    'period_start_date' => $start,
                    'period_end_date' => $end,
                    'template_version' => $version,
                    'status' => PeriodicReportStatus::Draft,
                ]);

                $templateSections = ReportTemplateSection::query()
                    ->where('ministry_id', $attache->ministry_id)
                    ->where('version', $version)
                    ->orderBy('section_order')
                    ->get();

                foreach ($templateSections as $templateSection) {
                    ReportSection::create([
                        'periodic_report_id' => $report->id,
                        'report_template_section_id' => $templateSection->id,
                        'content' => null,
                    ]);
                }

                return $report;
            });
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            throw new InvalidArgumentException(
                'A draft report already exists for this mission and reporting period (BR-007).'
            );
        }
    }

    /**
     * FR-RPT-006: auto-save. Only narrative sections accept free-text
     * content; throttled to at most one write per 5 seconds per section so
     * frontend auto-save polling cannot cause a write storm.
     */
    public function saveSectionContent(ReportSection $section, ?string $content): void
    {
        if ($section->reportTemplateSection->section_type !== SectionType::Narrative) {
            throw new InvalidArgumentException('Only narrative sections accept free-text content (FR-RPT-005).');
        }

        $throttleKey = "report-section-autosave:{$section->id}";

        if (Cache::has($throttleKey)) {
            return;
        }

        Cache::put($throttleKey, true, 5);

        $section->forceFill(['content' => $content])->save();
    }

    /**
     * FR-RPT-007: validates rowData keys against the parent
     * report_template_section.column_schema before persisting.
     *
     * @param  array<string, mixed>  $rowData
     *
     * @throws InvalidArgumentException If the section is not a
     *                                  structured_table section, an unknown
     *                                  column key is supplied, or a
     *                                  mandatory column is missing.
     */
    public function addDataRow(ReportSection $section, array $rowData, int $order): ReportDataRow
    {
        $templateSection = $section->reportTemplateSection;

        if ($templateSection->section_type !== SectionType::StructuredTable) {
            throw new InvalidArgumentException('Only structured_table sections accept data rows (FR-RPT-007).');
        }

        $schema = collect($templateSection->column_schema ?? []);
        $allowedKeys = $schema->pluck('name')->all();

        $unknownKeys = array_diff(array_keys($rowData), $allowedKeys);

        if ($unknownKeys !== []) {
            throw new InvalidArgumentException(
                'Unknown column key(s) for this section: '.implode(', ', $unknownKeys).' (FR-RPT-007).'
            );
        }

        $missingMandatory = $schema
            ->filter(fn (array $column) => ($column['mandatory'] ?? false) && ! array_key_exists($column['name'], $rowData))
            ->pluck('name')
            ->all();

        if ($missingMandatory !== []) {
            throw new InvalidArgumentException(
                'Missing mandatory column(s): '.implode(', ', $missingMandatory).' (FR-RPT-007).'
            );
        }

        return ReportDataRow::create([
            'report_section_id' => $section->id,
            'row_order' => $order,
            'row_data' => $rowData,
        ]);
    }

    /**
     * FR-RPT-008.
     */
    public function removeDataRow(ReportDataRow $row): void
    {
        $row->delete();
    }

    /**
     * FR-RPT-009.
     */
    public function reorderDataRow(ReportDataRow $row, int $newOrder): void
    {
        $row->forceFill(['row_order' => $newOrder])->save();
    }

    /**
     * FR-RPT-011: copies data rows from the matching section (by title, so
     * this still works across a template version boundary) of the most
     * recent prior submitted report for the same mission. Narrative content
     * is never carried forward.
     */
    public function carryForwardRows(ReportSection $section, PeriodicReport $priorReport): void
    {
        $templateSection = $section->reportTemplateSection;

        if ($templateSection->section_type !== SectionType::StructuredTable) {
            return;
        }

        $priorSection = ReportSection::query()
            ->withoutGlobalScopes()
            ->where('periodic_report_id', $priorReport->id)
            ->whereHas('reportTemplateSection', fn ($query) => $query->where('section_title', $templateSection->section_title))
            ->first();

        if ($priorSection === null) {
            return;
        }

        foreach ($priorSection->dataRows()->orderBy('row_order')->get() as $priorRow) {
            ReportDataRow::create([
                'report_section_id' => $section->id,
                'row_order' => $priorRow->row_order,
                'row_data' => $priorRow->row_data,
            ]);
        }
    }

    /**
     * Orchestrates carryForwardRows() across every structured_table section
     * of $report, resolving the most recent prior submitted report for the
     * same mission and ministry automatically (FR-RPT-011).
     *
     * @throws InvalidArgumentException If no prior submitted report exists
     *                                  for this mission.
     */
    public function carryForwardAllSections(PeriodicReport $report): void
    {
        $priorReport = PeriodicReport::query()
            ->withoutGlobalScopes()
            ->where('mission_id', $report->mission_id)
            ->where('ministry_id', $report->ministry_id)
            ->where('status', PeriodicReportStatus::Submitted)
            ->where('id', '!=', $report->id)
            ->orderByDesc('period_start_date')
            ->first();

        if ($priorReport === null) {
            throw new InvalidArgumentException('No prior submitted report exists for this mission to carry forward from (FR-RPT-011).');
        }

        $report->loadMissing('sections.reportTemplateSection');

        foreach ($report->sections as $section) {
            $this->carryForwardRows($section, $priorReport);
        }
    }

    /**
     * FR-RPT-014, BR-009: submission is allowed regardless of section
     * completion — no mandatory-completion gate here, per CLAUDE.md's
     * explicit resolution of Follow-Up Item 2. FR-RPT-016/BR-010: late if
     * submitted after the 15th-of-next-month deadline computed from this
     * report's own period_end_date.
     *
     * The scheduled report:send-reminders command (App\Console\Commands\
     * SendReportReminders) recomputes its "not yet submitted" mission list
     * fresh from getComplianceDashboard() on every run rather than
     * pre-queuing a reminder at draft-creation time — so once a report is
     * submitted here, it simply stops appearing in the next run's list.
     * That is how a reminder is "cancelled" by an early submission; there is
     * no separate queued-job cancellation to perform.
     */
    public function submitReport(PeriodicReport $report, User $attache): void
    {
        $deadline = $this->resolveSubmissionDeadline(Carbon::parse($report->period_end_date));
        $submittedAt = Carbon::now();
        $isLate = $submittedAt->greaterThan($deadline);

        $report->forceFill([
            'status' => PeriodicReportStatus::Submitted,
            'submitted_at' => $submittedAt,
            'is_late' => $isLate,
        ])->save();

        $this->auditService->record(
            $attache,
            'periodic_report.submitted',
            'periodic_report',
            $report->id,
            ['is_late' => $isLate, 'submitted_at' => $submittedAt->toIso8601String()],
        );
    }

    /**
     * FR-RPT-018: per-mission submission status for $periodLabel within
     * $ministryId — submitted on time, submitted late, or not yet
     * submitted. Missions are resolved from mission_ministry_links (the
     * ministry's assigned missions), not every Mission row, mirroring
     * Admin\MissionController's established use of that join table.
     *
     * @return array{period_label: string, missions: Collection<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function getComplianceDashboard(string $ministryId, string $periodLabel): array
    {
        $missions = MissionMinistryLink::query()
            ->where('ministry_id', $ministryId)
            ->with('mission')
            ->get()
            ->pluck('mission')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();

        $reportsByMission = PeriodicReport::query()
            ->withoutGlobalScopes()
            ->where('ministry_id', $ministryId)
            ->where('reporting_period_label', $periodLabel)
            ->get()
            ->keyBy('mission_id');

        $missionRows = $missions->map(function (Mission $mission) use ($reportsByMission): array {
            $report = $reportsByMission->get($mission->id);

            $status = match (true) {
                $report === null || $report->status !== PeriodicReportStatus::Submitted => 'not_yet_submitted',
                $report->is_late => 'submitted_late',
                default => 'submitted_on_time',
            };

            return [
                'mission_id' => $mission->id,
                'mission_name' => $mission->name,
                'status' => $status,
                'submitted_at' => $report?->submitted_at,
            ];
        })->values();

        return [
            'period_label' => $periodLabel,
            'missions' => $missionRows,
            'summary' => [
                'submitted_on_time' => $missionRows->where('status', 'submitted_on_time')->count(),
                'submitted_late' => $missionRows->where('status', 'submitted_late')->count(),
                'not_yet_submitted' => $missionRows->where('status', 'not_yet_submitted')->count(),
            ],
        ];
    }

    /**
     * CLAUDE.md Section 8 Reporting Calendar: the fiscal quarter most
     * recently ended (the one currently open for submission) and its
     * 15th-of-next-month deadline. Used both as GET
     * /periodic-reports/compliance's default period and by
     * report:send-reminders to decide whether today is a configured
     * reminder lead time.
     *
     * @return array{label: string, start: Carbon, end: Carbon, deadline: Carbon}
     */
    public function currentSubmissionPeriod(): array
    {
        $quarterStartMonths = [1, 4, 7, 10];
        $today = Carbon::today();

        $currentQuarterStartMonth = collect($quarterStartMonths)->last(fn (int $month) => $month <= $today->month);
        $currentQuarterStart = Carbon::create($today->year, $currentQuarterStartMonth, 1);

        $periodStart = $currentQuarterStart->copy()->subMonths(3);
        $periodEnd = $currentQuarterStart->copy()->subDay();

        return [
            'label' => $this->periodLabelFor($periodStart),
            'start' => $periodStart,
            'end' => $periodEnd,
            'deadline' => $this->resolveSubmissionDeadline($periodEnd),
        ];
    }

    /**
     * CLAUDE.md Section 8: Q1 Jul-Sep, Q2 Oct-Dec, Q3 Jan-Mar, Q4 Apr-Jun,
     * labelled by the calendar year the quarter starts in (matches the
     * "Q1 2027" for Jul-Sep 2027 / "Q4 2026" for Apr-Jun 2026 convention
     * already used by tests and seed data).
     */
    private function periodLabelFor(Carbon $quarterStart): string
    {
        $quarterNumber = match ($quarterStart->month) {
            7 => 1,
            10 => 2,
            1 => 3,
            4 => 4,
        };

        return "Q{$quarterNumber} {$quarterStart->year}";
    }

    /**
     * CLAUDE.md Section 8: deadline is the 15th of the month following the
     * reporting period's end date (period_end_date is always the last day
     * of a month under the fixed quarterly calendar, so adding a day always
     * lands on the 1st of the next month).
     */
    private function resolveSubmissionDeadline(Carbon $periodEndDate): Carbon
    {
        return $periodEndDate->copy()->addDay()->addDays(14)->endOfDay();
    }

    private function resolveActiveVersion(string $ministryId): int
    {
        $version = ReportTemplateSection::query()
            ->where('ministry_id', $ministryId)
            ->where('effective_date', '<=', now()->toDateString())
            ->max('version');

        if ($version === null) {
            throw new InvalidArgumentException('No active report template is configured for this ministry.');
        }

        return (int) $version;
    }
}
