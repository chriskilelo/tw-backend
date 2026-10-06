<?php

namespace App\Http\Requests\Api\Governance;

use App\Http\Requests\Api\FormRequest;
use App\Services\GovernanceService;
use Illuminate\Validation\Rule;

/**
 * GET /mission-activity (FR-HOM-001): the Head or Deputy Head of Mission's
 * feed. The mission is always the viewer's own and is never a parameter.
 * Filters narrow it by department (AC3), record type, status and fiscal
 * quarter; `sort` is "-date" (newest first, the default) or "date".
 */
class MissionActivityFeedRequest extends FormRequest
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
            'ministry_id' => ['sometimes', 'nullable', 'uuid'],
            'type' => ['sometimes', 'nullable', Rule::in(GovernanceService::TYPES)],
            'status' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[a-z_]+$/'],
            'period' => ['sometimes', 'nullable', 'string', GovernanceSummaryRequest::quarterLabelRule()],
            'sort' => ['sometimes', 'nullable', Rule::in(['date', '-date'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
