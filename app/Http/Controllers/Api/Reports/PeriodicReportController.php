<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Reports\StoreDraftReportRequest;
use App\Http\Requests\Api\Reports\StoreReportDataRowRequest;
use App\Http\Requests\Api\Reports\UpdateReportSectionRequest;
use App\Http\Resources\PeriodicReportResource;
use App\Models\PeriodicReport;
use App\Models\ReportDataRow;
use App\Models\ReportSection;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * API-001, FR-RPT-003 to 011, 014, 016, 018 (Periodic Report Engine). Thin
 * controller: every mutation is delegated to ReportService (CLAUDE.md
 * Section 11); ministry scoping is applied automatically by the
 * ministry.scope route middleware plus the PeriodicReport model's global
 * scope.
 */
class PeriodicReportController extends Controller
{
    use ApiResponds;

    private const array DETAIL_RELATIONS = ['mission', 'authoredBy', 'sections.reportTemplateSection', 'sections.dataRows'];

    public function __construct(private readonly ReportService $reportService) {}

    /**
     * FR-RPT-018 AC (list): a Ministry Attache is always locked to their own
     * mission (BR-001), regardless of any mission_id query param; every
     * other ministry-scoped role may filter by mission_id or see every
     * mission in their ministry.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PeriodicReport::class);

        $perPage = min((int) $request->integer('per_page', 25), 100);
        $user = $request->user();
        $isAttache = $user->role?->name === 'Ministry Attache';

        $reports = PeriodicReport::query()
            ->with(['mission', 'authoredBy'])
            ->when($isAttache, fn ($query) => $query->where('mission_id', $user->mission_id))
            ->when(! $isAttache && $request->filled('mission_id'), fn ($query) => $query->where('mission_id', $request->string('mission_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('reporting_period_label'), fn ($query) => $query->where('reporting_period_label', $request->string('reporting_period_label')))
            ->orderByDesc('period_start_date')
            ->paginate($perPage)
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

    public function store(StoreDraftReportRequest $request): JsonResponse
    {
        Gate::authorize('create', PeriodicReport::class);

        try {
            $report = $this->reportService->createDraftReport(
                $request->user(),
                $request->validated('reporting_period_label'),
                Carbon::parse($request->validated('period_start_date')),
                Carbon::parse($request->validated('period_end_date')),
            );
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData(new PeriodicReportResource($report->load(self::DETAIL_RELATIONS)), 201);
    }

    public function show(PeriodicReport $periodicReport): JsonResponse
    {
        Gate::authorize('view', $periodicReport);

        return $this->respondWithData(new PeriodicReportResource($periodicReport->load(self::DETAIL_RELATIONS)));
    }

    public function updateSection(UpdateReportSectionRequest $request, PeriodicReport $periodicReport, ReportSection $section): JsonResponse
    {
        Gate::authorize('updateSection', $periodicReport);

        abort_unless($section->periodic_report_id === $periodicReport->id, 404);

        try {
            $this->reportService->saveSectionContent($section, $request->validated('content'));
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData(new PeriodicReportResource($periodicReport->fresh(self::DETAIL_RELATIONS)));
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

        return $this->respondWithData(new PeriodicReportResource($periodicReport->fresh(self::DETAIL_RELATIONS)), 201);
    }

    public function destroyDataRow(PeriodicReport $periodicReport, ReportDataRow $dataRow): JsonResponse
    {
        Gate::authorize('removeDataRow', $periodicReport);

        abort_unless($dataRow->reportSection->periodic_report_id === $periodicReport->id, 404);

        $this->reportService->removeDataRow($dataRow);

        return $this->respondWithData(new PeriodicReportResource($periodicReport->fresh(self::DETAIL_RELATIONS)));
    }

    public function carryForward(PeriodicReport $periodicReport): JsonResponse
    {
        Gate::authorize('carryForward', $periodicReport);

        try {
            $this->reportService->carryForwardAllSections($periodicReport);
        } catch (InvalidArgumentException $e) {
            return $this->respondWithErrors([$e->getMessage()], 422);
        }

        return $this->respondWithData(new PeriodicReportResource($periodicReport->fresh(self::DETAIL_RELATIONS)));
    }

    public function submit(Request $request, PeriodicReport $periodicReport): JsonResponse
    {
        Gate::authorize('submit', $periodicReport);

        $this->reportService->submitReport($periodicReport, $request->user());

        return $this->respondWithData(new PeriodicReportResource($periodicReport->fresh(self::DETAIL_RELATIONS)));
    }

    /**
     * FR-RPT-018: Ministry HQ Director / Ministry PS only
     * (ReportPolicy::viewCompliance()). Defaults to the currently open
     * reporting period when no period_label query param is supplied.
     */
    public function compliance(Request $request): JsonResponse
    {
        Gate::authorize('viewCompliance', PeriodicReport::class);

        $periodLabel = $request->filled('period_label')
            ? (string) $request->string('period_label')
            : $this->reportService->currentSubmissionPeriod()['label'];

        return $this->respondWithData(
            $this->reportService->getComplianceDashboard($request->user()->ministry_id, $periodLabel)
        );
    }
}
