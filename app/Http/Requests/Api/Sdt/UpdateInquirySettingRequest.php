<?php

namespace App\Http\Requests\Api\Sdt;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-SDT-020: edits, reorders, or deactivates a configured inquiry
 * category, workflow status, or event type. category is not editable,
 * matching StoreAlertFieldRequest's sibling UpdateAlertFieldRequest.
 */
class UpdateInquirySettingRequest extends FormRequest
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
            'value' => ['sometimes', 'string', 'max:255'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
