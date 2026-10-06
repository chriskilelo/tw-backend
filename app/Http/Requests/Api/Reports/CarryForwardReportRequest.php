<?php

namespace App\Http\Requests\Api\Reports;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-RPT-011 (API-001 Section 5: "Populate a structured table section from
 * the prior period's report"): `section_id` names the table to carry
 * forward; without it every structured table is carried forward.
 */
class CarryForwardReportRequest extends FormRequest
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
            'section_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
