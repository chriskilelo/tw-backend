<?php

namespace App\Http\Requests\Api\Sdt;

use App\Services\HqWorkspaceService;
use Illuminate\Validation\Rule;

/**
 * GET /sdt/hq-workspace/inquiries (FR-SDT-013, FR-INQ-009): the inquiries an
 * attache has notified HQ of. `status` groups the workflow statuses: "open"
 * (received, in progress, pending an external response; the default),
 * "resolved", "closed" (closed or cancelled) or "all". `high_value` keeps
 * only flagged inquiries. `sort` orders by the latest HQ notification
 * ("-notified_at", the default) or by the date received.
 */
class HqWorkspaceInquiriesRequest extends HqWorkspaceRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function sectionRules(): array
    {
        return [
            ...$this->listRules(),
            'status' => ['sometimes', 'nullable', Rule::in(array_keys(HqWorkspaceService::INQUIRY_STATUS_GROUPS))],
            'high_value' => ['sometimes', 'nullable', Rule::in(['1', '0', 'true', 'false'])],
            'sort' => ['sometimes', 'nullable', Rule::in(['-notified_at', 'notified_at', '-date_received', 'date_received'])],
        ];
    }
}
