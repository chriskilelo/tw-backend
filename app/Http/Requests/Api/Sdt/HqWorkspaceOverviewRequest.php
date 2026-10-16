<?php

namespace App\Http\Requests\Api\Sdt;

/**
 * GET /sdt/hq-workspace (FR-SDT-012): the portfolio, the effective scope,
 * the section counts and the filter options. Accepts only the shared
 * `scope` and `mission_id` filters.
 */
class HqWorkspaceOverviewRequest extends HqWorkspaceRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function sectionRules(): array
    {
        return [];
    }
}
