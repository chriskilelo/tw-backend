<?php

namespace App\Http\Requests\Api\Directives;

use App\Http\Requests\Api\FormRequest;
use App\Models\Directive;
use App\Services\DirectiveService;
use Illuminate\Validation\Rule;

/**
 * FR-DIR-006, FR-DIR-007 and the issuer's withdraw/close steps. The target
 * status must be one of the configured transition targets — 'draft' and
 * 'issued' are not reachable here (issuing happens at creation). Whether
 * the move is legal from the directive's current status is checked by
 * DirectiveService::transitionStatus(); who may request it is checked by
 * DirectivePolicy::transitionStatus() in the controller, because the
 * ability depends on the requested status validated here.
 *
 * 'note' is the FR-DIR-007 completion summary for 'completed' and the
 * withdrawal reason for 'cancelled' (required for both), and an optional
 * note otherwise.
 *
 * BR-018: mission_id and target_user_id are immutable once issued, so both
 * are explicitly prohibited rather than silently ignored.
 *
 * authorize() answers 403 before validation to anyone who may not request
 * the given status (or, when the status is missing or unknown, any status
 * at all), so a non-participant or read-only role never learns validation
 * details. The controller re-checks the exact validated status.
 */
class UpdateDirectiveStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $directive = $this->route('directive');

        if ($user === null || ! $directive instanceof Directive) {
            return false;
        }

        $requested = $this->input('status');
        $candidates = is_string($requested) && array_key_exists($requested, DirectiveService::ALLOWED_TRANSITIONS)
            ? [$requested]
            : array_keys(DirectiveService::ALLOWED_TRANSITIONS);

        return collect($candidates)->contains(fn (string $status): bool => $user->can('transitionStatus', [$directive, $status]));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(array_keys(DirectiveService::ALLOWED_TRANSITIONS))],
            'note' => ['nullable', 'string', 'max:5000', 'required_if:status,'.implode(',', DirectiveService::NOTE_REQUIRED_STATUSES)],
            'mission_id' => ['prohibited'],
            'target_user_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'The requested status is not a valid directive status change.',
            'note.required_if' => $this->input('status') === 'cancelled'
                ? 'Give a reason for withdrawing this directive.'
                : 'A completion summary is required to complete a directive.',
            'note.max' => 'The note may not be longer than 5000 characters.',
            'mission_id.prohibited' => 'The target mission of an issued directive cannot be changed; issue a new directive instead.',
            'target_user_id.prohibited' => 'The target attache of an issued directive cannot be changed; issue a new directive instead.',
        ];
    }
}
