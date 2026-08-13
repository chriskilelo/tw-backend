<?php

namespace App\Http\Requests\Api\Alerts;

use App\Http\Requests\Api\FormRequest;

/**
 * CLAUDE.md Section 8 Alert Field Schema / BR-011: only country and
 * intelligence_type are configured mandatory; every other field is
 * optional and may be left blank at submission.
 */
class StoreAlertRequest extends FormRequest
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
            'country' => ['required', 'string', 'max:100'],
            'sector' => ['nullable', 'string', 'max:100'],
            'product_category' => ['nullable', 'string', 'max:150'],
            'product_description' => ['nullable', 'string'],
            'intelligence_type' => ['required', 'string', 'in:opportunities,trade_barriers'],
            'intelligence_source' => ['nullable', 'string', 'max:255'],
            'urgency' => ['nullable', 'string', 'max:50'],
            'confidence_rating' => ['nullable', 'string', 'max:50'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
        ];
    }
}
