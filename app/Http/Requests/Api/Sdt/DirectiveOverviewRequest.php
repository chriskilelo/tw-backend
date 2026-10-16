<?php

namespace App\Http\Requests\Api\Sdt;

use App\Http\Requests\Api\FormRequest;
use App\Models\Directive;
use Illuminate\Validation\Rule;

/**
 * GET /sdt/directives/overview and /compliance: the directive oversight
 * board (DirectivePolicy::viewOverview(): Ministry HQ Director, Ministry PS,
 * Acting PS). Authorised before validation, so any other role gets 403
 * whatever it sends. `mission_id` must be a mission posted to the viewer's
 * department; `issued_by_user_id` an account of that department.
 */
class DirectiveOverviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewOverview', Directive::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $ministryId = $this->user()?->ministry_id;

        return [
            'mission_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('mission_ministry_links', 'mission_id')->where('ministry_id', $ministryId),
            ],
            'issued_by_user_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('users', 'id')->where('ministry_id', $ministryId),
            ],
        ];
    }
}
