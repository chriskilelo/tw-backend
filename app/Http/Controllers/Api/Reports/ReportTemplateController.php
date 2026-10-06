<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Reports\StoreReportTemplateRequest;
use App\Models\ReportTemplateSection;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * API-001 Section 5, BR-006: report template versions. Creating a version is
 * for the System Administrator (or a Ministry Administrator, own department
 * only); the Ministry PS may also read them. Thin controller: every mutation
 * is delegated to ReportService (CLAUDE.md Section 11).
 */
class ReportTemplateController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly ReportService $reportService) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewTemplates', ReportTemplateSection::class);

        $sections = ReportTemplateSection::query()
            ->when($request->filled('ministry_id'), fn ($query) => $query->where('ministry_id', $request->string('ministry_id')))
            ->when($request->filled('version'), fn ($query) => $query->where('version', $request->integer('version')))
            ->orderBy('ministry_id')
            ->orderBy('version')
            ->orderBy('section_order')
            ->get();

        $versions = $sections->groupBy(fn (ReportTemplateSection $section) => "{$section->ministry_id}:{$section->version}")
            ->map(fn ($group) => [
                'ministry_id' => $group->first()->ministry_id,
                'version' => $group->first()->version,
                'effective_date' => $group->first()->effective_date,
                'sections' => $group->map($this->present(...))->values(),
            ])
            ->values();

        return $this->respondWithData($versions);
    }

    public function store(StoreReportTemplateRequest $request): JsonResponse
    {
        Gate::authorize('manageTemplate', ReportTemplateSection::class);

        $sections = $this->reportService->createTemplateVersion(
            $request->validated('ministry_id'),
            Carbon::parse($request->validated('effective_date')),
            $request->validated('sections'),
        );

        return $this->respondWithData($sections->map($this->present(...)), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ReportTemplateSection $section): array
    {
        return [
            'id' => $section->id,
            'ministry_id' => $section->ministry_id,
            'version' => $section->version,
            'effective_date' => $section->effective_date,
            'section_order' => $section->section_order,
            'section_title' => $section->section_title,
            'section_type' => $section->section_type,
            'column_schema' => $section->column_schema,
            'table_config' => $section->table_config,
            'guidance_text' => $section->guidance_text,
        ];
    }
}
