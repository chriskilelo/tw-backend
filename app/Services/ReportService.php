<?php

namespace App\Services;

use App\Enums\PeriodicReportStatus;
use App\Enums\SectionType;
use App\Models\AuditLog;
use App\Models\MasterDataEntry;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Models\PeriodicReport;
use App\Models\ReportDataRow;
use App\Models\ReportSection;
use App\Models\ReportTemplateSection;
use App\Models\User;
use App\Policies\ReportPolicy;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * CLAUDE.md Section 11 / FR-RPT-001 to 019: business logic for the Periodic
 * Report Engine. Reports\ReportTemplateController,
 * Reports\PeriodicReportController and Sdt\ReportsController stay thin and
 * delegate here.
 *
 * Report templates are versioned and never overwritten (BR-006): a new
 * version is always a new set of report_template_sections rows.
 * createDraftReport() pins the version active at creation time onto
 * periodic_reports.template_version, so a later template edit never changes
 * an existing report.
 *
 * Every write to a report's content locks the report row and re-checks that
 * it is still a draft, so an auto-save racing a submission can never land
 * in a submitted report (BR-009, FR-RPT-015).
 */
class ReportService
{
    /**
     * Longest narrative section accepted (characters). A quarterly report
     * section is a few pages at most; the cap only stops abuse.
     */
    public const int SECTION_CONTENT_MAX = 50000;

    /**
     * Longest single table cell accepted (characters).
     */
    public const int CELL_TEXT_MAX = 2000;

    /**
     * Quarters offered by the period pickers: the one in progress and the
     * eight before it (two financial years).
     */
    public const int RECENT_PERIODS = 9;

    /**
     * Reporting periods shown in the compliance history grid.
     */
    public const int HISTORY_PERIODS = 6;

    /**
     * Days before the deadline on which report:send-reminders nudges every
     * mission yet to submit (CLAUDE.md Section 12).
     *
     * @var array<int, int>
     */
    public const array REMINDER_LEAD_DAYS = [7, 3];

    /**
     * Pre-populated master data values split into their label columns on
     * this separator ("2110300 — Personal Allowances-FSA").
     */
    private const string LABEL_SEPARATOR = ' — ';

    /**
     * FR-SEARCH-001: a submitted report's search document: mission and
     * period (weight A), narrative text (B) and table cell values (C).
     */
    private const string SEARCH_VECTOR_SQL = <<<'SQL'
        UPDATE periodic_reports AS pr
        SET search_vector =
            setweight(to_tsvector('english', coalesce(m.name, '') || ' ' || coalesce(m.host_country, '') || ' ' || pr.reporting_period_label), 'A')
            || setweight(to_tsvector('english', coalesce((
                SELECT string_agg(rs.content, ' ')
                FROM report_sections rs
                WHERE rs.periodic_report_id = pr.id
            ), '')), 'B')
            || setweight(to_tsvector('english', coalesce((
                SELECT string_agg(cell.value, ' ')
                FROM report_sections rs
                JOIN report_data_rows rdr ON rdr.report_section_id = rs.id
                CROSS JOIN LATERAL jsonb_each_text(
                    CASE WHEN jsonb_typeof(rdr.row_data) = 'object' THEN rdr.row_data ELSE '{}'::jsonb END
                ) AS cell
                WHERE rs.periodic_report_id = pr.id
            ), '')), 'C')
        FROM missions m
        WHERE m.id = pr.mission_id
          AND pr.id = ?
        SQL;

    public function __construct(
        private readonly AuditService $auditService,
        private readonly NotificationService $notificationService,
    ) {}

    // --- Templates -----------------------------------------------------------

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
     * @param  array<int, array{section_order:int, section_title:string, section_type:string, column_schema?: array<int, array<string, mixed>>|null, table_config?: array<string, mixed>|null, guidance_text?: ?string}>  $sections
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
                'table_config' => $section['table_config'] ?? null,
                'guidance_text' => $section['guidance_text'] ?? null,
            ]));
        });
    }

    // --- Reporting calendar ------------------------------------------------

    /**
     * CLAUDE.md Section 8: Q1 Jul-Sep, Q2 Oct-Dec, Q3 Jan-Mar, Q4 Apr-Jun,
     * labelled by the calendar year the quarter starts in ("Q1 2027" is
     * Jul-Sep 2027, "Q4 2026" is Apr-Jun 2026).
     */
    public function periodLabelFor(Carbon $quarterStart): string
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
     * CLAUDE.md Section 8: the deadline is the 15th of the month following
     * the reporting period's end (BR-010).
     */
    public function resolveSubmissionDeadline(Carbon $periodEndDate): Carbon
    {
        return PeriodicReport::deadlineForPeriodEnd($periodEndDate);
    }

    /**
     * @return array{label: string, start: Carbon, end: Carbon, deadline: Carbon}
     *
     * @throws InvalidArgumentException If $start is not the first day of a quarter.
     */
    public function periodStartingOn(Carbon $start): array
    {
        $start = $start->copy()->startOfDay();

        if ($start->day !== 1 || ! in_array($start->month, [1, 4, 7, 10], true)) {
            throw new InvalidArgumentException('A reporting period starts on 1 January, 1 April, 1 July or 1 October.');
        }

        $end = $start->copy()->addMonths(3)->subDay();

        return [
            'label' => $this->periodLabelFor($start),
            'start' => $start,
            'end' => $end,
            'deadline' => $this->resolveSubmissionDeadline($end),
        ];
    }

    /**
     * The period named by a "Q1 2027"-style label, or null for anything
     * else.
     *
     * @return array{label: string, start: Carbon, end: Carbon, deadline: Carbon}|null
     */
    public function periodForLabel(string $label): ?array
    {
        if (! preg_match('/^Q([1-4]) (\d{4})$/', trim($label), $matches)) {
            return null;
        }

        $month = [1 => 7, 2 => 10, 3 => 1, 4 => 4][(int) $matches[1]];

        return $this->periodStartingOn(Carbon::create((int) $matches[2], $month, 1));
    }

    /**
     * @return array{label: string, start: Carbon, end: Carbon, deadline: Carbon}
     */
    public function periodContaining(Carbon $date): array
    {
        return $this->periodStartingOn(Carbon::create($date->year, intdiv($date->month - 1, 3) * 3 + 1, 1));
    }

    /**
     * The fiscal quarter most recently ended — the one currently open for
     * submission — and its deadline. The default period of the compliance
     * dashboards and the one report:send-reminders works on.
     *
     * @return array{label: string, start: Carbon, end: Carbon, deadline: Carbon}
     */
    public function currentSubmissionPeriod(): array
    {
        return $this->periodStartingOn($this->periodContaining(Carbon::today())['start']->copy()->subMonths(3));
    }

    /**
     * Newest first: the quarter in progress, then the ones before it.
     *
     * @return array<int, array{label: string, start: Carbon, end: Carbon, deadline: Carbon}>
     */
    public function recentPeriods(int $count = self::RECENT_PERIODS): array
    {
        $inProgress = $this->periodContaining(Carbon::today());

        return collect(range(0, $count - 1))
            ->map(fn (int $offset): array => $this->periodStartingOn($inProgress['start']->copy()->subMonths(3 * $offset)))
            ->all();
    }

    /**
     * in_progress (the quarter is still running), open (ended, deadline
     * ahead), closed (deadline passed) or upcoming.
     *
     * @param  array{label: string, start: Carbon, end: Carbon, deadline: Carbon}  $period
     */
    public function periodPhase(array $period): string
    {
        return match (true) {
            Carbon::today()->lt($period['start']) => 'upcoming',
            Carbon::today()->lte($period['end']) => 'in_progress',
            Carbon::now()->lte($period['deadline']) => 'open',
            default => 'closed',
        };
    }

    /**
     * @param  array{label: string, start: Carbon, end: Carbon, deadline: Carbon}  $period
     * @return array{label: string, start: string, end: string, deadline: string, phase: string, days_to_deadline: int}
     */
    public function presentPeriod(array $period): array
    {
        return [
            'label' => $period['label'],
            'start' => $period['start']->toDateString(),
            'end' => $period['end']->toDateString(),
            'deadline' => $period['deadline']->toDateString(),
            'phase' => $this->periodPhase($period),
            'days_to_deadline' => (int) Carbon::today()->diffInDays($period['deadline']->copy()->startOfDay(), false),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function availablePeriods(): array
    {
        return array_map($this->presentPeriod(...), $this->recentPeriods());
    }

    /**
     * FR-RPT-003: the period a new draft covers, from its label ("Q1 2027"),
     * its start date, or both (which must agree). An end date, when given,
     * must be the quarter's last day. A quarter that has not begun cannot be
     * reported on; any earlier one can (a late report is still owed).
     *
     * @return array{label: string, start: Carbon, end: Carbon, deadline: Carbon}
     *
     * @throws InvalidArgumentException
     */
    public function resolveRequestedPeriod(?string $label, ?string $startDate, ?string $endDate): array
    {
        $label = $label !== null && trim($label) !== '' ? trim($label) : null;
        $fromLabel = $label !== null ? $this->periodForLabel($label) : null;

        if ($label !== null && $fromLabel === null) {
            throw new InvalidArgumentException('Choose a reporting period such as "Q1 2027" (Q1 runs from July to September).');
        }

        $fromStart = $startDate !== null && $startDate !== '' ? $this->periodStartingOn(Carbon::parse($startDate)) : null;
        $period = $fromLabel ?? $fromStart;

        if ($period === null) {
            throw new InvalidArgumentException('Choose the reporting period this report covers.');
        }

        if ($fromLabel !== null && $fromStart !== null && $fromLabel['label'] !== $fromStart['label']) {
            throw new InvalidArgumentException("{$fromLabel['label']} starts on {$fromLabel['start']->toDateString()}, not {$fromStart['start']->toDateString()}.");
        }

        if ($endDate !== null && $endDate !== '' && Carbon::parse($endDate)->toDateString() !== $period['end']->toDateString()) {
            throw new InvalidArgumentException("{$period['label']} ends on {$period['end']->toDateString()}.");
        }

        if ($period['start']->isAfter(Carbon::today())) {
            throw new InvalidArgumentException("{$period['label']} has not started yet. Reports can be started for the current quarter or an earlier one.");
        }

        return $period;
    }

    // --- Drafts ------------------------------------------------------------

    /**
     * FR-RPT-003, BR-007: only one report instance may exist per mission per
     * reporting period, enforced by the database unique index on
     * (mission_id, ministry_id, period_start_date, period_end_date) and
     * caught here, so a race between two concurrent requests can never slip
     * through. One report_sections row is created per active template
     * section, and structured tables configured with pre-populated rows
     * (FR-RPT-008) receive them.
     *
     * @throws InvalidArgumentException If a report already exists for this
     *                                  mission and period (BR-007), or no
     *                                  template is active.
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
                    $section = ReportSection::create([
                        'periodic_report_id' => $report->id,
                        'report_template_section_id' => $templateSection->id,
                        'content' => null,
                    ]);

                    if ($templateSection->section_type !== SectionType::StructuredTable) {
                        continue;
                    }

                    foreach ($this->prepopulatedRows($templateSection, $attache->ministry_id) as $index => $rowData) {
                        ReportDataRow::create([
                            'report_section_id' => $section->id,
                            'row_order' => $index + 1,
                            'row_data' => $rowData,
                        ]);
                    }
                }

                return $report;
            });
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            throw new InvalidArgumentException("A report for {$periodLabel} already exists for this mission (BR-007).");
        }
    }

    /**
     * FR-RPT-008: one row per active master data entry of the section's
     * configured category, labels filled, values left for the attache. An
     * entry standing in for the computed total row is skipped (FR-RPT-009).
     *
     * @return array<int, array<string, string>>
     */
    public function prepopulatedRows(ReportTemplateSection $templateSection, string $ministryId): array
    {
        $category = $templateSection->prepopulateCategory();
        $columnNames = array_column($templateSection->columns(), 'name');
        $labelColumns = array_values(array_intersect($templateSection->labelColumns(), $columnNames));

        if ($category === null || $labelColumns === []) {
            return [];
        }

        return MasterDataEntry::query()
            ->where('ministry_id', $ministryId)
            ->where('category', $category)
            ->where('active', true)
            ->orderBy('display_order')
            ->orderBy('value')
            ->pluck('value')
            ->map(function (string $value) use ($labelColumns): array {
                $parts = array_map('trim', explode(self::LABEL_SEPARATOR, $value, count($labelColumns)));

                return collect($labelColumns)
                    ->mapWithKeys(fn (string $column, int $index): array => [$column => $parts[$index] ?? ''])
                    ->filter(fn (string $label): bool => $label !== '')
                    ->all();
            })
            ->reject(fn (array $row): bool => $row === [] || $templateSection->isTotalPlaceholder($row))
            ->values()
            ->all();
    }

    /**
     * Discards a draft started in error: its rows, sections and the report
     * itself. A submitted report is the official record and is never
     * deleted (the lock re-checks it is still a draft).
     */
    public function discardDraft(PeriodicReport $report): void
    {
        $this->withLockedDraft($report, function (PeriodicReport $locked): void {
            $sectionIds = ReportSection::query()->withoutGlobalScopes()->where('periodic_report_id', $locked->id)->pluck('id');

            ReportDataRow::query()->withoutGlobalScopes()->whereIn('report_section_id', $sectionIds)->delete();
            ReportSection::query()->withoutGlobalScopes()->where('periodic_report_id', $locked->id)->delete();

            $locked->delete();
        });
    }

    // --- Section content -----------------------------------------------------

    /**
     * FR-RPT-005/006: auto-save of a narrative section. Every changed save is
     * persisted — never dropped, so leaving a section straight after typing
     * cannot lose the last words — and an unchanged one is a no-op, so
     * repeated auto-saves never cause a write storm. Whitespace-only content
     * is stored as empty.
     */
    public function saveSectionContent(ReportSection $section, ?string $content): void
    {
        $this->withLockedDraft($section->periodicReport, function () use ($section, $content): void {
            if ($section->reportTemplateSection->section_type !== SectionType::Narrative) {
                throw new InvalidArgumentException('Only narrative sections accept free-text content (FR-RPT-005).');
            }

            $content = $content === null || trim($content) === '' ? null : $content;

            if ($section->content === $content) {
                return;
            }

            $section->forceFill(['content' => $content])->save();
        });
    }

    /**
     * FR-RPT-007: saves a structured table section's rows as a whole, in
     * display order — adding, editing, reordering and removing rows in one
     * auto-save. A row keeps its id across saves; a new row may carry a
     * client-generated id. Rows left out are removed. A draft may hold rows
     * still being filled in, so mandatory cells are not enforced here
     * (FR-RPT-012); types always are (FR-RPT-010).
     *
     * @param  array<int, array{id?: string|null, row_data?: mixed}>  $rows
     *
     * @throws InvalidArgumentException
     */
    public function saveSectionRows(ReportSection $section, array $rows): void
    {
        $this->withLockedDraft($section->periodicReport, function () use ($section, $rows): void {
            $templateSection = $section->reportTemplateSection;
            $errors = $this->sectionRowErrors($templateSection, $rows);

            if ($errors !== []) {
                throw new InvalidArgumentException(implode(' ', $errors));
            }

            $existing = $section->dataRows()->get()->keyBy('id');
            $newIds = collect($rows)
                ->pluck('id')
                ->filter(fn (mixed $id): bool => is_string($id))
                ->map(fn (string $id): string => strtolower($id))
                ->reject(fn (string $id): bool => $existing->has($id))
                ->values()
                ->all();

            if ($this->foreignRowIds($section, $newIds)) {
                throw new InvalidArgumentException('A row id belongs to another table.');
            }

            $keptIds = [];

            foreach (array_values($rows) as $index => $row) {
                [$rowData] = $this->normalizeRow($templateSection, (array) ($row['row_data'] ?? []));
                $id = isset($row['id']) && is_string($row['id']) ? strtolower($row['id']) : null;
                $attributes = ['row_order' => $index + 1, 'row_data' => $rowData];

                if ($id !== null && $existing->has($id)) {
                    $existing->get($id)->forceFill($attributes)->save();
                } else {
                    $created = new ReportDataRow(['report_section_id' => $section->id, ...$attributes]);

                    if ($id !== null) {
                        $created->id = $id;
                    }

                    $created->save();
                    $id = $created->id;
                }

                $keptIds[] = $id;
            }

            $existing->except($keptIds)->each(fn (ReportDataRow $row) => $row->delete());
        });
    }

    /**
     * Validation for saveSectionRows(): the section must be a table, the row
     * count within its maximum, every id a row of this section or a new
     * one, and every value valid for its column. Messages name the row.
     *
     * @param  array<int, mixed>  $rows
     * @return array<int, string>
     */
    public function sectionRowErrors(ReportTemplateSection $templateSection, array $rows): array
    {
        if ($templateSection->section_type !== SectionType::StructuredTable) {
            return ['Only structured table sections have rows (FR-RPT-007).'];
        }

        if (count($rows) > $templateSection->maxRows()) {
            return ["This table holds at most {$templateSection->maxRows()} rows."];
        }

        $errors = [];
        $ids = [];

        foreach (array_values($rows) as $index => $row) {
            $rowNumber = $index + 1;

            if (! is_array($row) || (array_key_exists('row_data', $row) && ! is_array($row['row_data']))) {
                $errors[] = "Row {$rowNumber} is not a valid row.";

                continue;
            }

            $id = $row['id'] ?? null;

            if ($id !== null && (! is_string($id) || ! Str::isUuid($id))) {
                $errors[] = "Row {$rowNumber} has an invalid id.";
            } elseif ($id !== null) {
                $ids[$rowNumber] = strtolower($id);
            }

            [, $rowErrors] = $this->normalizeRow($templateSection, $row['row_data'] ?? [], $rowNumber);
            array_push($errors, ...$rowErrors);
        }

        if (count($ids) !== count(array_unique($ids))) {
            $errors[] = 'Two rows share the same id.';
        }

        return array_slice($errors, 0, 10);
    }

    /**
     * Ids that belong to rows of another section can never be adopted by
     * this one (a client-generated id must be new).
     *
     * @param  array<int, string>  $ids
     */
    public function foreignRowIds(ReportSection $section, array $ids): bool
    {
        return $ids !== [] && ReportDataRow::query()
            ->withoutGlobalScopes()
            ->whereIn('id', array_map('strtolower', $ids))
            ->where('report_section_id', '!=', $section->id)
            ->exists();
    }

    /**
     * FR-RPT-007/008: adds one complete row. Unknown columns and missing
     * mandatory cells are refused, and every value is type-checked
     * (FR-RPT-010).
     *
     * @param  array<string, mixed>  $rowData
     *
     * @throws InvalidArgumentException
     */
    public function addDataRow(ReportSection $section, array $rowData, int $order): ReportDataRow
    {
        return $this->withLockedDraft($section->periodicReport, function () use ($section, $rowData, $order): ReportDataRow {
            $templateSection = $section->reportTemplateSection;

            if ($templateSection->section_type !== SectionType::StructuredTable) {
                throw new InvalidArgumentException('Only structured_table sections accept data rows (FR-RPT-007).');
            }

            if ($section->dataRows()->count() >= $templateSection->maxRows()) {
                throw new InvalidArgumentException("This table holds at most {$templateSection->maxRows()} rows.");
            }

            [$normalized, $errors] = $this->normalizeRow($templateSection, $rowData, null, enforceMandatory: true);

            if ($errors !== []) {
                throw new InvalidArgumentException(implode(' ', $errors));
            }

            return ReportDataRow::create([
                'report_section_id' => $section->id,
                'row_order' => $order,
                'row_data' => $normalized,
            ]);
        });
    }

    /**
     * FR-RPT-007: removes one row. Removing a row that is already gone is a
     * no-op (DELETE is idempotent, CLAUDE.md Section 10).
     */
    public function removeDataRow(ReportDataRow $row): void
    {
        $this->withLockedDraft($row->reportSection->periodicReport, fn () => $row->delete());
    }

    public function reorderDataRow(ReportDataRow $row, int $newOrder): void
    {
        $this->withLockedDraft($row->reportSection->periodicReport, fn () => $row->forceFill(['row_order' => $newOrder])->save());
    }

    /**
     * FR-RPT-010: normalises one row against its column schema. Integer and
     * numeric cells must be numbers (stored as numbers; thousands
     * separators are a display concern); a selection cell must be one of
     * the configured options when a list is configured; a date cell must be
     * YYYY-MM-DD; text is trimmed. Empty cells become null. Unknown columns
     * are refused. Mandatory cells are required only with $enforceMandatory.
     *
     * @param  array<array-key, mixed>  $rowData
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    public function normalizeRow(ReportTemplateSection $templateSection, array $rowData, ?int $rowNumber = null, bool $enforceMandatory = false): array
    {
        $prefix = $rowNumber === null ? '' : "Row {$rowNumber}: ";
        $columns = collect($templateSection->columns())->keyBy('name');
        $unknown = array_diff(array_map('strval', array_keys($rowData)), $columns->keys()->all());

        if ($unknown !== []) {
            return [[], ["{$prefix}Unknown column key(s) for this section: ".implode(', ', $unknown).' (FR-RPT-007).']];
        }

        $normalized = [];
        $errors = [];

        foreach ($rowData as $name => $value) {
            $column = $columns->get((string) $name);
            [$cell, $error] = $this->normalizeCell($column, $value);

            if ($error !== null) {
                $errors[] = "{$prefix}{$name} {$error}";
            } elseif ($cell !== null) {
                $normalized[(string) $name] = $cell;
            }
        }

        if ($enforceMandatory) {
            $missing = $columns
                ->filter(fn (array $column): bool => $column['mandatory'] && ! array_key_exists($column['name'], $normalized))
                ->keys()
                ->all();

            if ($missing !== []) {
                $errors[] = "{$prefix}Missing mandatory column(s): ".implode(', ', $missing).' (FR-RPT-007).';
            }
        }

        return [$normalized, $errors];
    }

    /**
     * @param  array{name: string, type: string, mandatory: bool, options: array<int, string>|null}  $column
     * @return array{0: mixed, 1: string|null} the stored value (null when empty) and an error suffix
     */
    private function normalizeCell(array $column, mixed $value): array
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return [null, null];
        }

        if (! is_scalar($value) || is_bool($value)) {
            return [null, 'must be a single value.'];
        }

        $text = trim((string) $value);

        return match (strtolower($column['type'])) {
            'integer' => preg_match('/^-?\d{1,15}$/', $text) === 1
                ? [(int) $text, null]
                : [null, 'must be a whole number (FR-RPT-010).'],
            'numeric', 'number', 'decimal', 'currency' => is_numeric($text) && preg_match('/^-?\d{1,15}(\.\d{1,4})?$/', $text) === 1
                ? [str_contains($text, '.') ? (float) $text : (int) $text, null]
                : [null, 'must be a number, without thousands separators (FR-RPT-010).'],
            'selection' => $column['options'] !== null && ! in_array($text, $column['options'], true)
                ? [null, 'must be one of: '.implode(', ', $column['options']).'.']
                : [$text, null],
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 && Carbon::hasFormat($text, 'Y-m-d')
                ? [$text, null]
                : [null, 'must be a date (YYYY-MM-DD).'],
            default => mb_strlen($text) > self::CELL_TEXT_MAX
                ? [null, 'is longer than '.self::CELL_TEXT_MAX.' characters.']
                : [$text, null],
        };
    }

    // --- Carry forward -------------------------------------------------------

    /**
     * FR-RPT-011: the most recent submitted report of the same mission for
     * an earlier period — the one whose tables carry forward.
     */
    public function carryForwardSource(PeriodicReport $report): ?PeriodicReport
    {
        return PeriodicReport::query()
            ->withoutGlobalScopes()
            ->where('mission_id', $report->mission_id)
            ->where('ministry_id', $report->ministry_id)
            ->where('status', PeriodicReportStatus::Submitted->value)
            ->whereDate('period_start_date', '<', Carbon::parse($report->period_start_date)->toDateString())
            ->orderByDesc('period_start_date')
            ->first();
    }

    /**
     * FR-RPT-011: replaces a structured table's rows (or, with no $section,
     * every structured table's) with a copy of the matching section's rows
     * in the most recent earlier submitted report. Sections match by title,
     * so this works across a template version boundary (BR-006). Narrative
     * content is never carried forward; a placeholder total row never is
     * either. Replacing rather than appending makes a repeated carry-forward
     * harmless.
     *
     * @return int The number of rows copied.
     *
     * @throws InvalidArgumentException If there is nothing to copy.
     */
    public function carryForward(PeriodicReport $report, ?ReportSection $section = null): int
    {
        return $this->withLockedDraft($report, function (PeriodicReport $locked) use ($section): int {
            $source = $this->carryForwardSource($locked);

            if ($source === null) {
                throw new InvalidArgumentException('There is no earlier submitted report for this mission to carry forward from (FR-RPT-011).');
            }

            $targets = $section !== null
                ? collect([$section])
                : $locked->sections()->with('reportTemplateSection')->get();

            $targets = $targets->filter(fn (ReportSection $target): bool => $target->reportTemplateSection->section_type === SectionType::StructuredTable);

            if ($targets->isEmpty()) {
                throw new InvalidArgumentException('Only structured table sections can be carried forward (FR-RPT-011).');
            }

            $copied = 0;

            foreach ($targets as $target) {
                $copied += $this->copyRowsFrom($source, $target);
            }

            if ($copied === 0) {
                throw new InvalidArgumentException("The {$source->reporting_period_label} report has no table rows to carry forward.");
            }

            return $copied;
        });
    }

    /**
     * Backwards-compatible entry point: carries every structured table
     * forward.
     */
    public function carryForwardAllSections(PeriodicReport $report): void
    {
        $this->carryForward($report);
    }

    private function copyRowsFrom(PeriodicReport $source, ReportSection $target): int
    {
        $templateSection = $target->reportTemplateSection;

        $priorSection = ReportSection::query()
            ->withoutGlobalScopes()
            ->where('periodic_report_id', $source->id)
            ->whereHas('reportTemplateSection', fn ($query) => $query->where('section_title', $templateSection->section_title))
            ->first();

        if ($priorSection === null) {
            return 0;
        }

        $knownColumns = array_column($templateSection->columns(), 'name');
        $priorRows = ReportDataRow::query()
            ->withoutGlobalScopes()
            ->where('report_section_id', $priorSection->id)
            ->orderBy('row_order')
            ->get()
            ->map(fn (ReportDataRow $row): array => array_intersect_key((array) $row->row_data, array_flip($knownColumns)))
            ->reject(fn (array $rowData): bool => $rowData === [] || $templateSection->isTotalPlaceholder($rowData))
            ->values();

        if ($priorRows->isEmpty()) {
            return 0;
        }

        ReportDataRow::query()->withoutGlobalScopes()->where('report_section_id', $target->id)->delete();

        foreach ($priorRows->take($templateSection->maxRows()) as $index => $rowData) {
            ReportDataRow::create([
                'report_section_id' => $target->id,
                'row_order' => $index + 1,
                'row_data' => $rowData,
            ]);
        }

        return min($priorRows->count(), $templateSection->maxRows());
    }

    // --- Submission ----------------------------------------------------------

    /**
     * FR-RPT-014, BR-009: submission is allowed regardless of section
     * completion. FR-RPT-016/BR-010: late when submitted after this report's
     * own deadline (15th of the month after its period ends). The report row
     * is locked, so two concurrent submissions (or a submission racing an
     * auto-save) serialise and the second is refused. On success the report
     * joins the search index (FR-SEARCH-001), the audit trail records it
     * (FR-AUDIT-001) and the attache is notified (FR-RPT-014 AC1).
     *
     * The scheduled report:send-reminders command recomputes its "not yet
     * submitted" list fresh on every run, so a submitted report simply
     * stops appearing there: no queued reminder needs cancelling.
     *
     * @throws InvalidArgumentException If the report is no longer a draft.
     */
    public function submitReport(PeriodicReport $report, User $attache, bool $notify = true): PeriodicReport
    {
        $submitted = DB::transaction(function () use ($report, $attache): PeriodicReport {
            $locked = PeriodicReport::query()->withoutGlobalScopes()->whereKey($report->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->isDraft()) {
                throw new InvalidArgumentException('This report has already been submitted (BR-009).');
            }

            $submittedAt = Carbon::now();
            $isLate = $submittedAt->greaterThan($locked->deadline());

            $locked->forceFill([
                'status' => PeriodicReportStatus::Submitted,
                'submitted_at' => $submittedAt,
                'is_late' => $isLate,
            ])->save();

            DB::statement(self::SEARCH_VECTOR_SQL, [$locked->id]);

            $this->auditService->record(
                $attache,
                'periodic_report.submitted',
                'periodic_report',
                $locked->id,
                ['is_late' => $isLate, 'submitted_at' => $submittedAt->toIso8601String()],
                request()?->ip(),
                $locked->ministry_id,
            );

            return $locked;
        });

        if ($notify) {
            $timeliness = $submitted->is_late
                ? "late, {$submitted->daysOverdue()} day(s) after the deadline"
                : 'on time';

            $this->notificationService->notify(
                $attache,
                'report_submitted',
                "Your {$submitted->reporting_period_label} report was submitted {$timeliness}.",
                "/reports/{$submitted->id}",
            );
        }

        return $submitted;
    }

    /**
     * Who submitted a report and when, from the audit trail (FR-AUDIT-001);
     * falls back to the author for reports submitted before auditing.
     *
     * @return array{id: string, full_name: string}|null
     */
    public function submittedBy(PeriodicReport $report): ?array
    {
        if (! $report->isSubmitted()) {
            return null;
        }

        $user = AuditLog::query()
            ->with(['user' => fn ($query) => $query->withTrashed()])
            ->where('affected_entity_type', 'periodic_report')
            ->where('affected_entity_id', $report->id)
            ->where('action', 'periodic_report.submitted')
            ->latest('created_at')
            ->first()
            ?->user ?? $report->authoredBy;

        return $user === null ? null : ['id' => $user->id, 'full_name' => $user->full_name];
    }

    // --- Completion ----------------------------------------------------------

    /**
     * FR-RPT-013: empty, started or complete. A narrative section is
     * complete once it has text. A table is complete when it has rows and
     * every row has its mandatory cells; started when some value has been
     * entered (pre-populated labels do not count); otherwise empty.
     * Informational only: submission is never blocked on it (BR-009).
     */
    public function sectionCompletion(ReportSection $section): string
    {
        $templateSection = $section->reportTemplateSection;

        if ($templateSection->section_type !== SectionType::StructuredTable) {
            return trim((string) $section->content) === '' ? 'empty' : 'complete';
        }

        $columns = collect($templateSection->columns());
        $valueColumns = $columns->pluck('name')->diff($templateSection->labelColumns())->values();
        $mandatory = $columns->where('mandatory', true)->pluck('name');
        $rows = $section->dataRows
            ->map(fn (ReportDataRow $row): array => (array) $row->row_data)
            ->reject(fn (array $rowData): bool => $templateSection->isTotalPlaceholder($rowData))
            ->values();

        $filled = fn (array $rowData, string $column): bool => array_key_exists($column, $rowData) && $rowData[$column] !== null && $rowData[$column] !== '';
        $hasValue = $rows->contains(fn (array $rowData): bool => $valueColumns->contains(fn (string $column): bool => $filled($rowData, $column)));

        if (! $hasValue) {
            return 'empty';
        }

        return $rows->every(fn (array $rowData): bool => $mandatory->every(fn (string $column): bool => $filled($rowData, $column)))
            ? 'complete'
            : 'started';
    }

    /**
     * @param  iterable<ReportSection>  $sections
     * @return array{total: int, complete: int, started: int, empty: int}
     */
    public function progressOf(iterable $sections): array
    {
        $states = collect($sections)->map($this->sectionCompletion(...));

        return [
            'total' => $states->count(),
            'complete' => $states->filter(fn (string $state): bool => $state === 'complete')->count(),
            'started' => $states->filter(fn (string $state): bool => $state === 'started')->count(),
            'empty' => $states->filter(fn (string $state): bool => $state === 'empty')->count(),
        ];
    }

    // --- Periods and missions for the pickers -------------------------------

    /**
     * The recent reporting periods, newest first, each with $user's own
     * mission's report for it when $user writes reports (the "start a
     * report" picker shows which periods are done, in progress or owed).
     *
     * @return array<int, array<string, mixed>>
     */
    public function reportingPeriodsFor(User $user): array
    {
        $periods = $this->recentPeriods();
        $isAuthor = in_array($user->role?->name, ReportPolicy::AUTHOR_ROLES, true) && $user->mission_id !== null;

        $reports = $isAuthor
            ? PeriodicReport::query()
                ->where('mission_id', $user->mission_id)
                ->whereIn('reporting_period_label', array_column($periods, 'label'))
                ->get()
                ->keyBy('reporting_period_label')
            : collect();

        return array_map(function (array $period) use ($reports, $isAuthor): array {
            $presented = $this->presentPeriod($period);

            if (! $isAuthor) {
                return $presented;
            }

            $report = $reports->get($period['label']);

            return [
                ...$presented,
                'report' => $report === null ? null : [
                    'id' => $report->id,
                    'status' => $report->status,
                    'is_late' => $report->is_late,
                    'submitted_at' => $report->submitted_at,
                    'days_overdue' => $report->daysOverdue(),
                ],
            ];
        }, $periods);
    }

    /**
     * The missions a user can filter the report list by: their own for an
     * attache or mission-governance role, else the ministry's linked
     * missions.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function filterMissionsFor(User $user): array
    {
        $role = $user->role?->name;

        if (in_array($role, [...ReportPolicy::AUTHOR_ROLES, ...ReportPolicy::MISSION_OVERSIGHT_ROLES], true)) {
            $mission = $user->mission_id === null ? null : Mission::query()->find($user->mission_id);

            return $mission === null ? [] : [['id' => $mission->id, 'name' => $mission->name]];
        }

        if ($user->ministry_id === null) {
            return [];
        }

        return Mission::query()
            ->whereIn('id', MissionMinistryLink::query()->where('ministry_id', $user->ministry_id)->select('mission_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Mission $mission): array => ['id' => $mission->id, 'name' => $mission->name])
            ->all();
    }

    // --- Compliance ------------------------------------------------------------

    /**
     * FR-RPT-018: everything the compliance dashboards render — each
     * mission's status for the requested period (default: the one open for
     * submission; a quarter not yet started or an unrecognised label falls
     * back to it), the periods to choose from, and the history grid.
     *
     * @return array<string, mixed>
     */
    public function complianceBoard(string $ministryId, ?string $requestedLabel = null): array
    {
        $label = $this->resolveCompliancePeriodLabel($requestedLabel);

        return [
            ...$this->getComplianceDashboard($ministryId, $label),
            'available_periods' => $this->availablePeriods(),
            'history' => $this->getComplianceHistory($ministryId, $label),
        ];
    }

    /**
     * The compliance period a request names, falling back to the one open
     * for submission when it names none, an unrecognised label, or a
     * quarter that has not started.
     */
    public function resolveCompliancePeriodLabel(?string $requestedLabel): string
    {
        $requested = $requestedLabel !== null && $requestedLabel !== '' ? $this->periodForLabel($requestedLabel) : null;

        return $requested !== null && ! $requested['start']->isAfter(Carbon::today())
            ? $requested['label']
            : $this->currentSubmissionPeriod()['label'];
    }

    /**
     * FR-RPT-018: per-mission submission status for $periodLabel within
     * $ministryId — Submitted On Time, Submitted Late, Draft In Progress or
     * Not Started — computed from the reports themselves, so it changes the
     * moment a report is started or submitted. Missions come from
     * mission_ministry_links (the ministry's postings); an inactive mission
     * (BR-003) appears only if it reported for the period. A mission whose
     * post is vacant has no attache. Drafts carry their section progress
     * (FR-RPT-013), never their content; only a submitted report carries its
     * id, since HQ reads submitted reports only (FR-RPT-017).
     *
     * `not_yet_submitted` in the summary is draft_in_progress + not_started,
     * kept for the consumers written against the earlier three-state model.
     *
     * @return array{period_label: string, period: array<string, mixed>|null, missions: Collection<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function getComplianceDashboard(string $ministryId, string $periodLabel): array
    {
        $period = $this->periodForLabel($periodLabel);

        /** @var EloquentCollection<int, PeriodicReport> $reports */
        $reports = PeriodicReport::query()
            ->withoutGlobalScopes()
            ->where('ministry_id', $ministryId)
            ->where('reporting_period_label', $periodLabel)
            ->get();

        $reports->filter(fn (PeriodicReport $report): bool => $report->isDraft())
            ->load(['sections.reportTemplateSection', 'sections.dataRows']);

        $reportsByMission = $reports->keyBy('mission_id');
        [$missions, $attaches] = $this->complianceMissions($ministryId, $reportsByMission->keys()->all());

        $rows = $missions->map(function (Mission $mission) use ($reportsByMission, $attaches, $period): array {
            /** @var PeriodicReport|null $report */
            $report = $reportsByMission->get($mission->id);
            $attache = $attaches->get($mission->id);
            $status = $report?->complianceStatus() ?? PeriodicReport::COMPLIANCE_NOT_STARTED;
            $deadline = $period['deadline'] ?? $report?->deadline();
            $isSubmitted = $report?->isSubmitted() ?? false;
            $isOverdue = ! $isSubmitted && $deadline !== null && Carbon::now()->greaterThan($deadline);

            return [
                'mission_id' => $mission->id,
                'mission_name' => $mission->name,
                'mission_city' => $mission->city,
                'host_country' => $mission->host_country,
                'attache' => $attache === null ? null : ['id' => $attache->id, 'full_name' => $attache->full_name],
                'status' => $status,
                'report_id' => $isSubmitted ? $report->id : null,
                'submitted_at' => $report?->submitted_at,
                'is_late' => (bool) $report?->is_late,
                'is_overdue' => $isOverdue,
                'days_overdue' => match (true) {
                    $isSubmitted => $report->daysOverdue(),
                    $isOverdue => max(1, (int) $deadline->copy()->startOfDay()->diffInDays(Carbon::today())),
                    default => null,
                },
                'progress' => $report !== null && $report->isDraft() ? $this->progressOf($report->sections) : null,
                'last_activity_at' => match (true) {
                    $report === null => null,
                    $isSubmitted => $report->submitted_at,
                    default => collect([$report->updated_at, ...$report->sections->pluck('updated_at')])->filter()->max(),
                },
            ];
        })->values();

        $count = fn (string $status): int => $rows->where('status', $status)->count();

        return [
            'period_label' => $periodLabel,
            'period' => $period === null ? null : $this->presentPeriod($period),
            'missions' => $rows,
            'summary' => [
                'submitted_on_time' => $count(PeriodicReport::COMPLIANCE_ON_TIME),
                'submitted_late' => $count(PeriodicReport::COMPLIANCE_LATE),
                'draft_in_progress' => $count(PeriodicReport::COMPLIANCE_DRAFT),
                'not_started' => $count(PeriodicReport::COMPLIANCE_NOT_STARTED),
                'not_yet_submitted' => $count(PeriodicReport::COMPLIANCE_DRAFT) + $count(PeriodicReport::COMPLIANCE_NOT_STARTED),
                'overdue' => $rows->where('is_overdue', true)->count(),
                'vacant' => $rows->whereNull('attache')->count(),
                'total' => $rows->count(),
            ],
        ];
    }

    /**
     * Compliance history (FR-RPT-018): each mission's status in the
     * $count periods up to and including $periodLabel, oldest first, with
     * per-period totals. Periods that have not started are never shown.
     *
     * @return array{periods: array<int, array<string, mixed>>, missions: array<int, array<string, mixed>>, totals: array<int, array<string, mixed>>}
     */
    public function getComplianceHistory(string $ministryId, string $periodLabel, int $count = self::HISTORY_PERIODS): array
    {
        $selected = $this->periodForLabel($periodLabel);

        if ($selected === null) {
            return ['periods' => [], 'missions' => [], 'totals' => []];
        }

        $periods = collect(range($count - 1, 0))
            ->map(fn (int $offset): array => $this->periodStartingOn($selected['start']->copy()->subMonths(3 * $offset)))
            ->values();
        $labels = $periods->pluck('label')->all();

        $reports = PeriodicReport::query()
            ->withoutGlobalScopes()
            ->where('ministry_id', $ministryId)
            ->whereIn('reporting_period_label', $labels)
            ->get(['id', 'mission_id', 'reporting_period_label', 'period_end_date', 'status', 'is_late', 'submitted_at'])
            ->groupBy('mission_id');

        [$missions] = $this->complianceMissions($ministryId, $reports->keys()->all());

        $missionRows = $missions->map(fn (Mission $mission): array => [
            'mission_id' => $mission->id,
            'mission_name' => $mission->name,
            'cells' => $periods->map(function (array $period) use ($reports, $mission): array {
                /** @var PeriodicReport|null $report */
                $report = $reports->get($mission->id, collect())->firstWhere('reporting_period_label', $period['label']);
                $isSubmitted = $report?->isSubmitted() ?? false;

                return [
                    'label' => $period['label'],
                    'status' => $report?->complianceStatus() ?? PeriodicReport::COMPLIANCE_NOT_STARTED,
                    'is_overdue' => ! $isSubmitted && Carbon::now()->greaterThan($period['deadline']),
                    'report_id' => $isSubmitted ? $report->id : null,
                ];
            })->all(),
        ])->values();

        $totals = $periods->map(function (array $period, int $index) use ($missionRows): array {
            $cells = $missionRows->map(fn (array $row): array => $row['cells'][$index]);

            return [
                'label' => $period['label'],
                'submitted_on_time' => $cells->where('status', PeriodicReport::COMPLIANCE_ON_TIME)->count(),
                'submitted_late' => $cells->where('status', PeriodicReport::COMPLIANCE_LATE)->count(),
                'draft_in_progress' => $cells->where('status', PeriodicReport::COMPLIANCE_DRAFT)->count(),
                'not_started' => $cells->where('status', PeriodicReport::COMPLIANCE_NOT_STARTED)->count(),
            ];
        })->values();

        return [
            'periods' => $periods->map($this->presentPeriod(...))->all(),
            'missions' => $missionRows->all(),
            'totals' => $totals->all(),
        ];
    }

    /**
     * The missions a ministry's compliance covers — its active postings,
     * plus any mission (even an inactive one) that reported in the window —
     * sorted by name, and each posting's active attache (null when vacant).
     *
     * @param  array<int, string>  $reportingMissionIds
     * @return array{0: Collection<int, Mission>, 1: Collection<string, User|null>}
     */
    private function complianceMissions(string $ministryId, array $reportingMissionIds): array
    {
        $links = MissionMinistryLink::query()
            ->where('ministry_id', $ministryId)
            ->with(['mission', 'activeAttache'])
            ->get();

        $missions = $links->pluck('mission')
            ->filter(fn (?Mission $mission): bool => $mission !== null && ($mission->active || in_array($mission->id, $reportingMissionIds, true)))
            ->merge(Mission::query()->whereIn('id', array_diff($reportingMissionIds, $links->pluck('mission_id')->all()))->get())
            ->unique('id')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $attaches = $links->mapWithKeys(fn (MissionMinistryLink $link): array => [$link->mission_id => $link->activeAttache]);

        return [$missions, $attaches];
    }

    // --- Internals -------------------------------------------------------------

    /**
     * Runs $callback with the report row locked, refusing once it is no
     * longer a draft (BR-009) — the guard every content write shares.
     *
     * @template TReturn
     *
     * @param  Closure(PeriodicReport): TReturn  $callback
     * @return TReturn
     */
    private function withLockedDraft(PeriodicReport $report, Closure $callback): mixed
    {
        return DB::transaction(function () use ($report, $callback): mixed {
            $locked = PeriodicReport::query()->withoutGlobalScopes()->whereKey($report->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->isDraft()) {
                throw new InvalidArgumentException('This report has been submitted and can no longer be edited (BR-009).');
            }

            return $callback($locked);
        });
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
