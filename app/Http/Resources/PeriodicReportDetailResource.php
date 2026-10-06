<?php

namespace App\Http\Resources;

use App\Enums\SectionType;
use App\Models\PeriodicReport;
use App\Models\ReportDataRow;
use App\Models\ReportSection;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /periodic-reports/{id} and every mutation that returns the report:
 * the list fields plus each section of the template version the report was
 * created under (BR-006) — its guidance, column schema, table behaviour
 * (pre-populated labels, total row, row maximum), content or rows, and
 * completion state (FR-RPT-013) — who submitted it, and allowed_actions for
 * the REQUESTING user, computed from ReportPolicy so the UI shows exactly
 * what the API accepts.
 *
 * @mixin PeriodicReport
 */
class PeriodicReportDetailResource extends PeriodicReportResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reportService = app(ReportService::class);
        $gate = $request->user() === null ? null : Gate::forUser($request->user());
        $allows = fn (string $ability): bool => $gate?->allows($ability, $this->resource) ?? false;
        $carryForwardSource = $allows('carryForward') ? $reportService->carryForwardSource($this->resource) : null;

        return [
            ...parent::toArray($request),
            'submitted_by' => $reportService->submittedBy($this->resource),
            'allowed_actions' => [
                'edit' => $allows('updateSection'),
                'submit' => $allows('submit'),
                'carry_forward' => $carryForwardSource !== null,
                'discard' => $allows('delete'),
            ],
            'carry_forward_source' => $carryForwardSource === null ? null : [
                'id' => $carryForwardSource->id,
                'reporting_period_label' => $carryForwardSource->reporting_period_label,
                'submitted_at' => $carryForwardSource->submitted_at,
            ],
            'sections' => $this->whenLoaded('sections', fn () => $this->sections
                ->sortBy(fn (ReportSection $section): int => $section->reportTemplateSection?->section_order ?? PHP_INT_MAX)
                ->values()
                ->map(fn (ReportSection $section): array => $this->presentSection($section, $reportService))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSection(ReportSection $section, ReportService $reportService): array
    {
        $template = $section->reportTemplateSection;
        $isTable = $template?->section_type === SectionType::StructuredTable;

        return [
            'id' => $section->id,
            'report_template_section_id' => $section->report_template_section_id,
            'section_title' => $template?->section_title,
            'section_type' => $template?->section_type,
            'section_order' => $template?->section_order,
            'column_schema' => $template?->column_schema,
            'guidance_text' => $template?->guidance_text,
            'table' => $isTable ? [
                'label_columns' => $template->labelColumns(),
                'prepopulated' => $template->prepopulateCategory() !== null,
                'total' => $template->totalConfig(),
                'max_rows' => $template->maxRows(),
            ] : null,
            'content' => $section->content,
            'data_rows' => $section->relationLoaded('dataRows') ? $section->dataRows
                ->sortBy('row_order')
                ->values()
                ->map(fn (ReportDataRow $row): array => [
                    'id' => $row->id,
                    'row_order' => $row->row_order,
                    'row_data' => $row->row_data,
                ]) : null,
            'completion' => $template === null ? 'empty' : $reportService->sectionCompletion($section),
            'updated_at' => $section->updated_at,
        ];
    }
}
