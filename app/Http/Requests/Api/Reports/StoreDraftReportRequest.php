<?php

namespace App\Http\Requests\Api\Reports;

use App\Http\Requests\Api\FormRequest;
use App\Services\ReportService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * FR-RPT-003. ministry_id, mission_id and authored_by_user_id are
 * system-derived from the authenticated attache's session (mirrors BR-014's
 * inquiry precedent) and never accepted from the request.
 *
 * The period is identified by its label ("Q1 2027"), its start date, or
 * both; it must be a real reporting quarter (CLAUDE.md Section 8) that has
 * already begun. Compliance (FR-RPT-018) groups reports by label, so a
 * free-typed or mismatched label would silently drop a report from the
 * dashboard.
 */
class StoreDraftReportRequest extends FormRequest
{
    /**
     * @var array{label: string, start: Carbon, end: Carbon, deadline: Carbon}|null
     */
    private ?array $reportingPeriod = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reporting_period_label' => ['required_without:period_start_date', 'nullable', 'string', 'max:50'],
            'period_start_date' => ['required_without:reporting_period_label', 'nullable', 'date_format:Y-m-d'],
            'period_end_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                try {
                    $this->reportingPeriod = app(ReportService::class)->resolveRequestedPeriod(
                        $this->input('reporting_period_label'),
                        $this->input('period_start_date'),
                        $this->input('period_end_date'),
                    );
                } catch (InvalidArgumentException $e) {
                    $validator->errors()->add('reporting_period_label', $e->getMessage());
                }
            },
        ];
    }

    /**
     * @return array{label: string, start: Carbon, end: Carbon, deadline: Carbon}
     */
    public function reportingPeriod(): array
    {
        return $this->reportingPeriod ?? app(ReportService::class)->resolveRequestedPeriod(
            $this->input('reporting_period_label'),
            $this->input('period_start_date'),
            $this->input('period_end_date'),
        );
    }
}
