<?php

namespace App\Http\Requests\Api\Directives;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-DIR-006, FR-DIR-007: the target status must be one of the
 * configured transition targets — 'draft' and 'issued' are not reachable
 * via this endpoint (issuing happens at creation). Whether the transition
 * is actually legal from the directive's current status is validated by
 * DirectiveService::transitionStatus() (depends on model state, not just
 * the request payload). 'note' doubles as the FR-DIR-007 completion
 * summary when status is 'completed'.
 *
 * BR-018: mission_id and target_user_id are immutable once issued. This
 * is the only PATCH endpoint the Directive and Tasking Engine exposes
 * (API-001 Section 8), so both fields are explicitly prohibited here
 * rather than merely omitted from the accepted field list — an omitted
 * field would be silently ignored by $request->validated(), which would
 * not surface a client's attempt to change them.
 */
class UpdateDirectiveStatusRequest extends FormRequest
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
            'status' => ['required', 'string', 'in:acknowledged,in_progress,completed,cancelled'],
            'note' => ['nullable', 'string', 'required_if:status,completed'],
            'mission_id' => ['prohibited'],
            'target_user_id' => ['prohibited'],
        ];
    }
}
