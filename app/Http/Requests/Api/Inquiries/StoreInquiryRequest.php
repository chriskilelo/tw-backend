<?php

namespace App\Http\Requests\Api\Inquiries;

use App\Http\Requests\Api\FormRequest;

/**
 * CLAUDE.md Section 8 Inquiry Categories / FR-INQ-002, 003, 022. country,
 * mission, and logged_by_user_id are system-derived from the authenticated
 * user's session context (BR-014) and are never accepted from the request.
 */
class StoreInquiryRequest extends FormRequest
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
            'category' => ['required', 'string', 'max:100'],
            'sub_type' => ['nullable', 'string', 'in:standard,dispute_or_complaint'],
            'inquirer_name' => ['required', 'string', 'max:255'],
            'inquirer_organisation' => ['nullable', 'string', 'max:255'],
            'inquirer_email' => ['nullable', 'email', 'max:255'],
            'inquirer_phone' => ['nullable', 'string', 'max:50'],
            'product_or_sector' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'date_received' => ['required', 'date'],
            'high_value_flag' => ['sometimes', 'boolean'],
            'high_value_justification' => ['nullable', 'string'],
        ];
    }
}
