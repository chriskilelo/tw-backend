<?php

namespace App\Http\Requests\Api\Sdt;

use Illuminate\Validation\Rule;

/**
 * GET /sdt/hq-workspace/reports (FR-SDT-015, FR-RPT-017): submitted
 * quarterly reports, read-only. `period` is a quarter label ("Q1 2026"),
 * `timeliness` "on_time" or "late", `sort` "-submitted_at" (newest first,
 * the default) or "submitted_at".
 */
class HqWorkspaceReportsRequest extends HqWorkspaceRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function sectionRules(): array
    {
        return [
            ...$this->listRules(),
            'period' => ['sometimes', 'nullable', 'string', 'regex:/^Q[1-4] \d{4}$/'],
            'timeliness' => ['sometimes', 'nullable', Rule::in(['on_time', 'late'])],
            'sort' => ['sometimes', 'nullable', Rule::in(['-submitted_at', 'submitted_at'])],
        ];
    }
}
