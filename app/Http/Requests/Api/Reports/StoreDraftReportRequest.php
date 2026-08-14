<?php

namespace App\Http\Requests\Api\Reports;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-RPT-003. ministry_id, mission_id, and authored_by_user_id are
 * system-derived from the authenticated attache's session context (mirrors
 * BR-014's inquiry precedent) and are never accepted from the request.
 */
class StoreDraftReportRequest extends FormRequest
{
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
            'reporting_period_label' => ['required', 'string', 'max:50'],
            'period_start_date' => ['required', 'date'],
            'period_end_date' => ['required', 'date', 'after_or_equal:period_start_date'],
        ];
    }
}
