<?php

namespace App\Http\Requests\Api\Inquiries;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-INQ-004: the submitting attache may edit inquiry fields, including the
 * high-value flag. BR-014: country, mission, logged_by_user_id, and
 * created_at are system-derived and never accepted here.
 */
class UpdateInquiryRequest extends FormRequest
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
            'category' => ['sometimes', 'string', 'max:100'],
            'sub_type' => ['sometimes', 'string', 'in:standard,dispute_or_complaint'],
            'inquirer_name' => ['sometimes', 'string', 'max:255'],
            'inquirer_organisation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'inquirer_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'inquirer_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'product_or_sector' => ['sometimes', 'nullable', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string'],
            'date_received' => ['sometimes', 'date'],
            'high_value_flag' => ['sometimes', 'boolean'],
            'high_value_justification' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
