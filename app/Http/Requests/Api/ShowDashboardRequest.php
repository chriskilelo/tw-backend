<?php

namespace App\Http\Requests\Api;

/**
 * GET /api/v1/dashboard. `kpi_cycle` picks the KPI cycle shown on the
 * attache and leadership views (FR-KPI-009); omitted, the latest completed
 * half-yearly cycle is used.
 */
class ShowDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'kpi_cycle' => ['sometimes', 'nullable', 'string', 'regex:/^(Q[1-4]|H[12]) \d{4}$/'],
        ];
    }
}
