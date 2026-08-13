<?php

namespace App\Http\Requests\Api\Alerts;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-ALERT-011: the submitting attache may edit any field of a previously
 * submitted alert; every field is optional on an update (only present
 * fields are applied by AlertService::editAlert()).
 */
class UpdateAlertRequest extends FormRequest
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
            'country' => ['sometimes', 'string', 'max:100'],
            'sector' => ['sometimes', 'nullable', 'string', 'max:100'],
            'product_category' => ['sometimes', 'nullable', 'string', 'max:150'],
            'product_description' => ['sometimes', 'nullable', 'string'],
            'intelligence_type' => ['sometimes', 'string', 'in:opportunities,trade_barriers'],
            'intelligence_source' => ['sometimes', 'nullable', 'string', 'max:255'],
            'urgency' => ['sometimes', 'nullable', 'string', 'max:50'],
            'confidence_rating' => ['sometimes', 'nullable', 'string', 'max:50'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string'],
        ];
    }
}
