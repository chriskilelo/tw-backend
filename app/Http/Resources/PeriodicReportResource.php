<?php

namespace App\Http\Resources;

use App\Models\PeriodicReport;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /periodic-reports list rows (FR-RPT-017): the report's period, status
 * and the derived timeliness flags — deadline, days overdue (FR-RPT-016 AC1)
 * and the FR-RPT-018 compliance status — computed once, on the model, so
 * the list, the detail page and the compliance dashboard agree. When the
 * sections are loaded (an attache's own list) each row also carries its
 * section progress (FR-RPT-013). PeriodicReportDetailResource extends this
 * with the sections themselves.
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
        $sectionsLoaded = $this->relationLoaded('sections');

        return [
            'id' => $this->id,
            'reporting_period_label' => $this->reporting_period_label,
            'period_start_date' => $this->period_start_date?->toDateString(),
            'period_end_date' => $this->period_end_date?->toDateString(),
            'template_version' => $this->template_version,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at,
            'is_late' => $this->is_late,
            'deadline' => $this->deadline()->toDateString(),
            'days_to_deadline' => $this->daysToDeadline(),
            'is_overdue' => $this->isOverdue(),
            'days_overdue' => $this->daysOverdue(),
            'compliance_status' => $this->complianceStatus(),
            'mission' => $this->whenLoaded('mission', fn () => $this->mission === null ? null : [
                'id' => $this->mission->id,
                'name' => $this->mission->name,
                'city' => $this->mission->city,
                'host_country' => $this->mission->host_country,
            ]),
            'ministry' => $this->whenLoaded('ministry', fn () => $this->ministry === null ? null : [
                'id' => $this->ministry->id,
                'name' => $this->ministry->name,
            ]),
            'authored_by' => $this->whenLoaded('authoredBy', fn () => $this->authoredBy === null ? null : [
                'id' => $this->authoredBy->id,
                'full_name' => $this->authoredBy->full_name,
            ]),
            'progress' => $this->when($sectionsLoaded, fn () => app(ReportService::class)->progressOf($this->sections)),
            'last_edited_at' => $this->when($sectionsLoaded, fn () => collect([$this->updated_at, ...$this->sections->pluck('updated_at')])->filter()->max()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
