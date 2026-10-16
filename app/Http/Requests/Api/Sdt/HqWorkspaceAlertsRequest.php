<?php

namespace App\Http\Requests\Api\Sdt;

use App\Services\HqWorkspaceService;
use Illuminate\Validation\Rule;

/**
 * GET /sdt/hq-workspace/alerts (FR-SDT-013): `queue` is "awaiting" (alerts
 * assigned to the officer and not yet acknowledged, the default),
 * "acknowledged" (assigned and acknowledged) or "feed" (every alert in the
 * current scope, whoever holds it). `sort` is "-created_at" (newest first,
 * the default) or "created_at".
 */
class HqWorkspaceAlertsRequest extends HqWorkspaceRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function sectionRules(): array
    {
        return [
            ...$this->listRules(),
            'queue' => ['sometimes', 'nullable', Rule::in(HqWorkspaceService::ALERT_QUEUES)],
            'intelligence_type' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^[a-z_]+$/'],
            'sort' => ['sometimes', 'nullable', Rule::in(['-created_at', 'created_at'])],
        ];
    }
}
