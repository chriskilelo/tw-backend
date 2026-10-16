<?php

namespace App\Http\Requests\Api\Sdt;

use App\Services\HqWorkspaceService;
use Illuminate\Validation\Rule;

/**
 * GET /sdt/hq-workspace/directives (FR-SDT-014, FR-DIR-009 to 011): the
 * directives the officer issued. `queue` is "attention" (completed and
 * awaiting the officer's closure, overdue, or stale; the default),
 * "awaiting_closure", "open" or "all". `sort` is "priority" (the default:
 * awaiting closure, then overdue, then stale), "target_completion_date",
 * "-created_at" or "last_progress_update_at" (longest without progress
 * first).
 */
class HqWorkspaceDirectivesRequest extends HqWorkspaceRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function sectionRules(): array
    {
        return [
            ...$this->listRules(),
            'queue' => ['sometimes', 'nullable', Rule::in(HqWorkspaceService::DIRECTIVE_QUEUES)],
            'sort' => ['sometimes', 'nullable', Rule::in(['priority', 'target_completion_date', '-created_at', 'last_progress_update_at'])],
        ];
    }
}
