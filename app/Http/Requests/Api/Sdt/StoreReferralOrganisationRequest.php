<?php

namespace App\Http\Requests\Api\Sdt;

use App\Http\Requests\Api\Concerns\PinsDepartmentForMinistryAdministrator;
use App\Http\Requests\Api\FormRequest;

/**
 * FR-SDT-023: adds an organisation to the referral registry.
 */
class StoreReferralOrganisationRequest extends FormRequest
{
    use PinsDepartmentForMinistryAdministrator;

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
            'ministry_id' => $this->departmentRules(),
            'name' => ['required', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
