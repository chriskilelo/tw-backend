<?php

namespace App\Http\Requests\Api\Directives;

use App\Http\Requests\Api\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PATCH /directives/{id} — FR-DIR-003 "optional and revisable". The issuer
 * may change the target completion date (null clears it to "No Date Set")
 * or the type category. BR-018 / FR-DIR-013: the target mission and
 * attache, like the description and the workflow fields, are explicitly
 * prohibited rather than silently ignored, so an attempt to retarget a
 * directive surfaces as a 422 instead of a quiet no-op.
 *
 * authorize() runs DirectivePolicy::revise() (issuer only) before
 * validation; DirectiveService::reviseDirective() answers 422 once the
 * directive is no longer open.
 */
class ReviseDirectiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('revise', $this->route('directive')) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'target_completion_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'type_category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'mission_id' => ['prohibited'],
            'target_user_id' => ['prohibited'],
            'description' => ['prohibited'],
            'status' => ['prohibited'],
            'issued_by_user_id' => ['prohibited'],
            'completion_summary' => ['prohibited'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->exists('target_completion_date') && ! $this->exists('type_category')) {
                    $validator->errors()->add('target_completion_date', 'Provide a new target completion date or directive type to revise.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_completion_date.date_format' => 'The target completion date must be a date in the format YYYY-MM-DD.',
            'target_completion_date.after_or_equal' => 'The target completion date cannot be in the past.',
            'type_category.max' => 'The directive type may not be longer than 100 characters.',
            'mission_id.prohibited' => 'The target mission of an issued directive cannot be changed; issue a new directive instead.',
            'target_user_id.prohibited' => 'The target attache of an issued directive cannot be changed; issue a new directive instead.',
            'description.prohibited' => 'The description of an issued directive cannot be changed; add a follow-up note instead.',
            'status.prohibited' => 'Change the status through the status endpoint.',
            'issued_by_user_id.prohibited' => 'The issuer of a directive cannot be changed.',
            'completion_summary.prohibited' => 'The completion summary is recorded when the attache completes the directive.',
        ];
    }
}
