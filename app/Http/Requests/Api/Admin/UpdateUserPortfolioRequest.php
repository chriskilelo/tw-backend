<?php

namespace App\Http\Requests\Api\Admin;

use App\Http\Requests\Api\FormRequest;
use App\Models\User;
use App\Services\AdministrationService;
use App\Support\HqPortfolio;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * PUT /users/{user}/portfolio (FR-SDT-012): replaces a Ministry HQ
 * Officer's portfolio as a whole, so all three lists are required (each may
 * be empty; three empty lists clear the portfolio). Missions must be posted
 * to the officer's own department; countries and sector keywords are short
 * plain names (HqPortfolio::COUNTRY_PATTERN, SECTOR_PATTERN), squished
 * before they are validated.
 *
 * Authorisation runs before validation: an account outside the actor's
 * department is a 404 and an account the actor may not manage a 403
 * whatever the body holds, so validation messages can never reveal which
 * missions another department has.
 */
class UpdateUserPortfolioRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $target = $this->route('user');

        if ($actor === null || ! $target instanceof User) {
            return false;
        }

        abort_unless(AdministrationService::canAdministerAccount($actor, $target), 404);

        return $actor->can('managePortfolio', $target);
    }

    protected function prepareForValidation(): void
    {
        foreach (['countries', 'sectors'] as $key) {
            if (is_array($this->input($key))) {
                $this->merge([$key => array_map(fn (mixed $value): mixed => is_string($value) ? Str::squish($value) : $value, $this->input($key))]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $target = $this->route('user');
        $ministryId = $target instanceof User ? $target->ministry_id : null;

        return [
            'mission_ids' => ['present', 'array', 'max:'.HqPortfolio::MAX_MISSIONS],
            'mission_ids.*' => [
                'uuid',
                'distinct',
                Rule::exists('mission_ministry_links', 'mission_id')->where('ministry_id', $ministryId),
            ],
            'countries' => ['present', 'array', 'max:'.HqPortfolio::MAX_COUNTRIES],
            'countries.*' => ['string', 'min:2', 'max:'.HqPortfolio::MAX_COUNTRY_LENGTH, 'regex:'.HqPortfolio::COUNTRY_PATTERN],
            'sectors' => ['present', 'array', 'max:'.HqPortfolio::MAX_SECTORS],
            'sectors.*' => ['string', 'min:2', 'max:'.HqPortfolio::MAX_SECTOR_LENGTH, 'regex:'.HqPortfolio::SECTOR_PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mission_ids.*.exists' => 'Every portfolio mission must be posted to the officer’s department.',
            'mission_ids.*.distinct' => 'A mission appears in the portfolio more than once.',
            'countries.*.regex' => 'A country name may only hold letters, digits, spaces and . , \' ( ) & -.',
            'sectors.*.regex' => 'A sector keyword may only hold letters, digits, spaces and & \' / -, and must start and end with a letter or digit.',
        ];
    }
}
