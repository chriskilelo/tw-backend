<?php

namespace App\Http\Requests\Api\Sdt;

use App\Http\Requests\Api\FormRequest;
use App\Models\Ministry;
use App\Services\HqWorkspaceService;
use Illuminate\Validation\Rule;

/**
 * Shared base for GET /sdt/hq-workspace and its section lists (FR-SDT-012
 * to 015). Authorisation runs here, before validation, so a caller without
 * workspace access is refused with 403 whatever it sends and never learns
 * which filter values are valid (MinistryPolicy::viewHqWorkspace()).
 *
 * `scope` is "portfolio" (the officer's configured portfolio, the default
 * when one exists) or "department". `mission_id` must be a mission posted
 * to the officer's own department.
 */
abstract class HqWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewHqWorkspace', Ministry::class) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'scope' => ['sometimes', 'nullable', Rule::in(HqWorkspaceService::SCOPES)],
            'mission_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('mission_ministry_links', 'mission_id')->where('ministry_id', $this->user()?->ministry_id),
            ],
            ...$this->sectionRules(),
        ];
    }

    /**
     * Rules for a paginated, searchable section list.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function listRules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    abstract protected function sectionRules(): array;

    /**
     * The validated filters, with the shared ones always present.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'scope' => null,
            'mission_id' => null,
            'q' => null,
            'page' => 1,
            'per_page' => 25,
            ...array_filter($this->validated(), fn (mixed $value): bool => $value !== null && $value !== ''),
        ];
    }
}
