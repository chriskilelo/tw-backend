<?php

namespace App\Http\Requests\Api\Referrals;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-REF-002: organisation (from the configured registry), contact person,
 * referral date, referral method, reference number, and remarks. The
 * parent inquiry is resolved from the route, never accepted from the body.
 */
class StoreReferralRequest extends FormRequest
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
            'referral_organisation_id' => ['required', 'uuid', 'exists:referral_organisations,id'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'referral_date' => ['required', 'date'],
            'referral_method' => ['nullable', 'string', 'max:100'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
