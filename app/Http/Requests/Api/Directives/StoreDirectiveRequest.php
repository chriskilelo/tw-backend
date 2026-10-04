<?php

namespace App\Http\Requests\Api\Directives;

use App\Http\Requests\Api\FormRequest;
use App\Models\Directive;
use App\Models\Mission;
use App\Models\MissionMinistryLink;
use App\Services\DirectiveService;
use Closure;
use Illuminate\Support\Str;

/**
 * FR-DIR-002, FR-DIR-003, BR-018: mission_id and target_user_id are
 * captured only here, at creation, and are immutable afterwards.
 *
 * The mission must be active and linked to the issuer's ministry, and the
 * target must be an active Ministry Attache of the issuer's ministry posted
 * at that mission. Accepting any existing user would leak the directive
 * (via the issued notification) to another department. A target that does
 * not exist and one in another department get the same message, so the
 * endpoint confirms nothing about other departments' accounts.
 *
 * authorize() runs DirectivePolicy::create() first, so a role that may not
 * issue directives gets 403 before any of these ministry-dependent rules
 * are evaluated.
 */
class StoreDirectiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Directive::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'mission_id' => ['bail', 'required', 'uuid', $this->missionIsLinkedToMinistry(...)],
            'target_user_id' => ['bail', 'required', 'uuid', $this->targetIsAnAttacheAtMission(...)],
            'type_category' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:5000'],
            'target_completion_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mission_id.required' => 'Select the mission this directive is for.',
            'mission_id.uuid' => 'The selected mission is not valid.',
            'target_user_id.required' => 'Select the attache this directive is assigned to.',
            'target_user_id.uuid' => 'The selected attache is not valid.',
            'description.required' => 'Describe what the directive asks the attache to do.',
            'description.max' => 'The description may not be longer than 5000 characters.',
            'type_category.max' => 'The directive type may not be longer than 100 characters.',
            'target_completion_date.date_format' => 'The target completion date must be a date in the format YYYY-MM-DD.',
            'target_completion_date.after_or_equal' => 'The target completion date cannot be in the past.',
        ];
    }

    private function missionIsLinkedToMinistry(string $attribute, mixed $value, Closure $fail): void
    {
        $ministryId = $this->user()?->ministry_id;

        if (! Mission::query()->whereKey($value)->where('active', true)->exists()) {
            $fail('The selected mission does not exist or is no longer active.');

            return;
        }

        if ($ministryId === null || ! MissionMinistryLink::query()->where('mission_id', $value)->where('ministry_id', $ministryId)->exists()) {
            $fail('The selected mission is not linked to your ministry.');
        }
    }

    private function targetIsAnAttacheAtMission(string $attribute, mixed $value, Closure $fail): void
    {
        $ministryId = $this->user()?->ministry_id;

        $attache = $ministryId === null
            ? null
            : app(DirectiveService::class)->eligibleAttaches($ministryId)->whereKey($value)->first(['id', 'mission_id']);

        if ($attache === null) {
            $fail('The selected officer is not an active Ministry Attache in your ministry.');

            return;
        }

        $missionId = $this->input('mission_id');

        if (is_string($missionId) && Str::isUuid($missionId) && $attache->mission_id !== $missionId) {
            $fail('The selected attache is not posted at the selected mission.');
        }
    }
}
