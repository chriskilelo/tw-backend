<?php

namespace App\Http\Requests\Api\Governance;

use App\Http\Requests\Api\FormRequest;
use App\Services\GovernanceService;
use Illuminate\Validation\Rule;

/**
 * GET /mfa-awareness/submissions (FR-MFA-001 AC2): the metadata-only
 * submission log, filterable by mission, department, record type and fiscal
 * quarter. No status or free-text filter: an entry shows type, date,
 * mission and department only, and filtering on anything else would leak
 * what the entry hides.
 */
class MfaSubmissionLogRequest extends FormRequest
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
            'mission_id' => ['sometimes', 'nullable', 'uuid'],
            'ministry_id' => ['sometimes', 'nullable', 'uuid'],
            'type' => ['sometimes', 'nullable', Rule::in(GovernanceService::TYPES)],
            'period' => ['sometimes', 'nullable', 'string', GovernanceSummaryRequest::quarterLabelRule()],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
