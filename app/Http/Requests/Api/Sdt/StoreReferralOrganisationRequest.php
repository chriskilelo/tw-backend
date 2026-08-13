<?php

namespace App\Http\Requests\Api\Sdt;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-SDT-023: adds an organisation to the referral registry.
 */
class StoreReferralOrganisationRequest extends FormRequest
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
            'ministry_id' => ['required', 'uuid', 'exists:ministries,id'],
            'name' => ['required', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
