<?php

namespace App\Http\Resources;

use App\Models\PeriodicReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * POST /periodic-reports, GET /periodic-reports/{id}: full report detail
 * with its sections and, for structured_table sections, their data rows
 * (API-001, FR-RPT-003 to 011).
 *
 * @mixin PeriodicReport
 */
class PeriodicReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reporting_period_label' => $this->reporting_period_label,
            'period_start_date' => $this->period_start_date,
            'period_end_date' => $this->period_end_date,
            'template_version' => $this->template_version,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at,
            'is_late' => $this->is_late,
            'mission' => $this->whenLoaded('mission', fn () => [
                'id' => $this->mission->id,
                'name' => $this->mission->name,
            ]),
            'authored_by' => $this->whenLoaded('authoredBy', fn () => [
                'id' => $this->authoredBy->id,
                'full_name' => $this->authoredBy->full_name,
            ]),
            'sections' => $this->whenLoaded('sections', fn () => $this->sections->map(fn ($section) => [
                'id' => $section->id,
                'report_template_section_id' => $section->report_template_section_id,
                'section_title' => $section->relationLoaded('reportTemplateSection') ? $section->reportTemplateSection->section_title : null,
                'section_type' => $section->relationLoaded('reportTemplateSection') ? $section->reportTemplateSection->section_type : null,
                'section_order' => $section->relationLoaded('reportTemplateSection') ? $section->reportTemplateSection->section_order : null,
                'column_schema' => $section->relationLoaded('reportTemplateSection') ? $section->reportTemplateSection->column_schema : null,
                'guidance_text' => $section->relationLoaded('reportTemplateSection') ? $section->reportTemplateSection->guidance_text : null,
                'content' => $section->content,
                'data_rows' => $section->relationLoaded('dataRows') ? $section->dataRows->sortBy('row_order')->values()->map(fn ($row) => [
                    'id' => $row->id,
                    'row_order' => $row->row_order,
                    'row_data' => $row->row_data,
                ]) : null,
                'updated_at' => $section->updated_at,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
