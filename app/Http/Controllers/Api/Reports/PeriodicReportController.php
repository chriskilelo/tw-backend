<?php

namespace App\Http\Controllers\Api\Reports;

use App\Enums\PeriodicReportStatus;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Reports\CarryForwardReportRequest;
use App\Http\Requests\Api\Reports\StoreDraftReportRequest;
use App\Http\Requests\Api\Reports\StoreReportDataRowRequest;
use App\Http\Requests\Api\Reports\UpdateReportSectionRequest;
use App\Http\Resources\PeriodicReportDetailResource;
use App\Http\Resources\PeriodicReportResource;
use App\Models\Mission;
use App\Models\PeriodicReport;
use App\Models\ReportDataRow;
use App\Models\ReportSection;
use App\Models\User;
use App\Policies\ReportPolicy;
use App\Services\AuditService;
use App\Services\ReportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * API-001 Section 5, FR-RPT-003 to 018 (Periodic Report Engine). Thin
 * controller: every mutation is delegated to ReportService (CLAUDE.md
 * Section 11). Ministry scoping is applied by the ministry.scope middleware
 * plus the PeriodicReport global scope (another department's report is a
 * 404 at route binding); PeriodicReport::visibleTo() and ReportPolicy then
 * narrow reads per role.
 */
class PeriodicReportController extends Controller
{
    use ApiResponds;

    /**
     * @var array<string, array{0: string, 1: string}>
     */
    private const array SORTS = [
        '-period_start_date' => ['period_start_date', 'desc'],
        'period_start_date' => ['period_start_date', 'asc'],
        '-submitted_at' => ['submitted_at', 'desc'],
        'submitted_at' => ['submitted_at', 'asc'],
    ];

    public function __construct(
        private readonly ReportService $reportService,
        private readonly AuditService $auditService,
    ) {}

    /**
     * FR-RPT-017: filterable by mission, reporting period and submission
     * status, plus timeliness (on_time, late, overdue — an unsubmitted draft
     * past its deadline), free text over mission, period and author, and
     * (for the mission-governance roles, who see several departments) the
     * department. Unrecognised or invalid filter values are ignored
     * (CLAUDE.md Section 10).
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PeriodicReport::class);

        $user = $request->user();
        $status = PeriodicReportStatus::tryFrom($this->stringParam($request, 'status'));
        $missionId = $this->uuidParam($request, 'mission_id');
        $ministryId = $this->uuidParam($request, 'ministry_id');
        $period = trim($this->stringParam($request, 'period') ?: $this->stringParam($request, 'reporting_period_label'));
        $search = Str::limit(trim($this->stringParam($request, 'q')), 100, '');
        $sort = $this->stringParam($request, 'sort');
        $isAuthor = in_array($user->role?->name, ReportPolicy::AUTHOR_ROLES, true);

        $reports = PeriodicReport::query()
            ->visibleTo($user)
            ->with(['mission', 'ministry', 'authoredBy' => fn ($query) => $query->withTrashed()])
            ->when($isAuthor, fn (Builder $query) => $query->with(['sections.reportTemplateSection', 'sections.dataRows']))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status->value))
            ->when($missionId !== null, fn (Builder $query) => $query->where('mission_id', $missionId))
            ->when($ministryId !== null, fn (Builder $query) => $query->where('ministry_id', $ministryId))
            ->when($period !== '', fn (Builder $query) => $query->where('reporting_period_label', $period))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $pattern = '%'.addcslashes($search, '\\%_').'%';

                $query->where(fn (Builder $inner) => $inner
                    ->where('reporting_period_label', 'ilike', $pattern)
                    ->orWhereHas('mission', fn (Builder $mission) => $mission
                        ->where('name', 'ilike', $pattern)
                        ->orWhere('city', 'ilike', $pattern)
                        ->orWhere('host_country', 'ilike', $pattern))
                    ->orWhereHas('authoredBy', fn (Builder $author) => $author->withTrashed()->where('full_name', 'ilike', $pattern)));
            })
            ->when(is_string($request->query('timeliness')), fn (Builder $query) => match ($request->query('timeliness')) {
                'on_time' => $query->where('status', PeriodicReportStatus::Submitted->value)->where('is_late', false),
                'late' => $query->where('status', PeriodicReportStatus::Submitted->value)->where('is_late', true),
                'overdue' => $query->where('status', PeriodicReportStatus::Draft->value)
                    ->whereDate('period_end_date', '<', Carbon::today()->subDays(15)->toDateString()),
                default => $query,
            })
            ->tap(fn (Builder $query) => $this->applySort($query, $sort))
            ->paginate($this->perPage($request))
            ->withQueryString();

        return $this->respondWithData(
            PeriodicReportResource::collection($reports->items()),
            meta: [
                'current_page' => $reports->currentPage(),
                'per_page' => $reports->perPage(),
                'total' => $reports->total(),
                'last_page' => $reports->lastPage(),
            ],
        );
    }

    /**
     * The reporting calendar for the pickers: the recent quarters (with the
     * attache's own report for each, FR-RPT-003 AC2), the period currently
     * open for submission, and the missions this user may filter by.
     */
    public function periods(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PeriodicReport::class);

        $user = $request->user();

        return $this->respondWithData([
            'current' => $this->reportService->presentPeriod($this->reportService->currentSubmissionPeriod()),
            'periods' => $this->reportService->reportingPeriodsFor($user),
            'missions' => $this->reportService->filterMissionsFor($user),
            'can_create' => Gate::forUser($user)->allows('create', PeriodicReport::class),
        ]);
    }

    /**
     * FR-RPT-003: AC2 — when the mission already has a report for the period,
     * nothing is created and the response names the existing one, so the
     * client opens it instead of a duplicate (BR-007).
     */
    public function store(StoreDraftReportRequest $request): JsonResponse
    {
        Gate::authorize('create', PeriodicReport::class);

        $user = $request->user();
        $period = $request->reportingPeriod();
        $existing = $this->existingReportFor($user, $period['start'], $period['end']);

        if ($existing !== null) {
            return $this->duplicateResponse($existing);
        }

        try {
            $report = $this->reportService->createDraftReport($user, $period['label'], $period['start'], $period['end']);
        } catch (InvalidArgumentException $e) {
            $existing = $this->existingReportFor($user, $period['start'], $period['end']);

            return $existing !== null ? $this->duplicateResponse($existing) : $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData(new PeriodicReportDetailResource($report->load($this->detailRelations())), 201);
    }

    public function show(Request $request, PeriodicReport $periodicReport): JsonResponse
    {
        Gate::authorize('view', $periodicReport);

        $this->auditService->recordOversightAccess($request->user(), PeriodicReport::class, $periodicReport->id, $periodicReport->ministry_id, $request->ip());

        return $this->respondWithData(new PeriodicReportDetailResource($periodicReport->load($this->detailRelations())));
    }

    /**
     * FR-RPT-005/006/007: the auto-save of one section — narrative content,
     * or a table's rows as a whole.
     */
    public function updateSection(UpdateReportSectionRequest $request, PeriodicReport $periodicReport, ReportSection $section): JsonResponse
    {
        Gate::authorize('updateSection', $periodicReport);

        abort_unless($section->periodic_report_id === $periodicReport->id, 404);

        try {
            if ($request->isRowsUpdate()) {
                $this->reportService->saveSectionRows($section, (array) $request->input('rows', []));
            } else {
                $this->reportService->saveSectionContent($section, $request->input('content'));
            }
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData(new PeriodicReportDetailResource($periodicReport->fresh($this->detailRelations())));
    }

    public function storeDataRow(StoreReportDataRowRequest $request, PeriodicReport $periodicReport): JsonResponse
    {
        Gate::authorize('addDataRow', $periodicReport);

        $section = ReportSection::query()->findOrFail($request->validated('section_id'));

        abort_unless($section->periodic_report_id === $periodicReport->id, 404);

        $order = $request->validated('row_order') ?? ($section->dataRows()->max('row_order') + 1);

        try {
            $this->reportService->addDataRow($section, $request->validated('row_data'), $order);
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData(new PeriodicReportDetailResource($periodicReport->fresh($this->detailRelations())), 201);
    }

    /**
     * FR-RPT-007. Idempotent (CLAUDE.md Section 10): removing a row that is
     * already gone returns the report unchanged rather than a 404.
     */
    public function destroyDataRow(PeriodicReport $periodicReport, string $dataRow): JsonResponse
    {
        Gate::authorize('removeDataRow', $periodicReport);

        $row = Str::isUuid($dataRow) ? ReportDataRow::query()->with('reportSection')->find($dataRow) : null;

        if ($row !== null) {
            abort_unless($row->reportSection?->periodic_report_id === $periodicReport->id, 404);

            try {
                $this->reportService->removeDataRow($row);
            } catch (InvalidArgumentException $e) {
                return $this->respondWithErrors([$e->getMessage()], 422);
            }
        }

        return $this->respondWithData(new PeriodicReportDetailResource($periodicReport->fresh($this->detailRelations())));
    }

    /**
     * FR-RPT-011: one table (section_id) or every table.
     */
    public function carryForward(CarryForwardReportRequest $request, PeriodicReport $periodicReport): JsonResponse
    {
        Gate::authorize('carryForward', $periodicReport);

        $section = null;

        if ($request->filled('section_id')) {
            $section = ReportSection::query()->with('reportTemplateSection')->find($request->validated('section_id'));

            abort_unless($section !== null && $section->periodic_report_id === $periodicReport->id, 404);
        }

        try {
            $this->reportService->carryForward($periodicReport, $section);
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData(new PeriodicReportDetailResource($periodicReport->fresh($this->detailRelations())));
    }

    public function submit(Request $request, PeriodicReport $periodicReport): JsonResponse
    {
        Gate::authorize('submit', $periodicReport);

        try {
            $report = $this->reportService->submitReport($periodicReport, $request->user());
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData(new PeriodicReportDetailResource($report->load($this->detailRelations())));
    }

    /**
     * Discards a draft (never a submitted report). Idempotent: a report that
     * no longer exists — or never existed within this user's ministry — is
     * already in the requested end state.
     */
    public function destroy(string $periodicReport): Response|JsonResponse
    {
        $report = Str::isUuid($periodicReport) ? PeriodicReport::query()->find($periodicReport) : null;

        if ($report !== null) {
            Gate::authorize('delete', $report);

            try {
                $this->reportService->discardDraft($report);
            } catch (InvalidArgumentException $e) {
                return $this->respondWithErrors([$e->getMessage()], 422);
            }
        }

        return response()->noContent();
    }

    /**
     * FR-RPT-018: Ministry HQ Director, Ministry PS and Acting PS
     * (ReportPolicy::viewCompliance()). Defaults to the period currently
     * open for submission; `period_label` picks another.
     */
    public function compliance(Request $request): JsonResponse
    {
        Gate::authorize('viewCompliance', PeriodicReport::class);

        return $this->respondWithData($this->reportService->complianceBoard(
            $request->user()->ministry_id,
            $this->stringParam($request, 'period_label'),
        ));
    }

    /**
     * @return array<string|int, mixed>
     */
    private function detailRelations(): array
    {
        return [
            'mission',
            'ministry',
            'authoredBy' => fn ($query) => $query->withTrashed(),
            'sections.reportTemplateSection',
            'sections.dataRows',
        ];
    }

    private function existingReportFor(User $user, Carbon $start, Carbon $end): ?PeriodicReport
    {
        return PeriodicReport::query()
            ->where('mission_id', $user->mission_id)
            ->where('ministry_id', $user->ministry_id)
            ->whereDate('period_start_date', $start->toDateString())
            ->whereDate('period_end_date', $end->toDateString())
            ->first();
    }

    private function duplicateResponse(PeriodicReport $existing): JsonResponse
    {
        return $this->respondWithErrors(
            ["Your mission already has a report for {$existing->reporting_period_label}. Open it to continue (BR-007)."],
            422,
            ['existing_report' => ['id' => $existing->id, 'status' => $existing->status]],
        );
    }

    private function applySort(Builder $query, string $sort): void
    {
        if ($sort === 'mission' || $sort === '-mission') {
            $query->orderBy(
                Mission::query()->select('name')->whereColumn('missions.id', 'periodic_reports.mission_id'),
                $sort === 'mission' ? 'asc' : 'desc',
            );
        } else {
            [$column, $direction] = self::SORTS[$sort] ?? self::SORTS['-period_start_date'];
            $query->orderByRaw("{$column} {$direction} nulls last");
        }

        $query->orderByDesc('period_start_date')->orderBy('id');
    }

    /**
     * A scalar query value, or '' for a missing or array-valued one
     * (?status[]=x must be ignored like any other invalid filter, not 500).
     */
    private function stringParam(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : '';
    }

    private function uuidParam(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    private function perPage(Request $request): int
    {
        $value = $request->query('per_page');

        if (! is_string($value) || ! is_numeric($value)) {
            return 25;
        }

        return max(1, min(100, (int) $value));
    }
}
