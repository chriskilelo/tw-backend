<?php

namespace App\Http\Requests\Api\Sdt;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-SDT-023: edits or deactivates a referral registry organisation.
 */
class UpdateReferralOrganisationRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
