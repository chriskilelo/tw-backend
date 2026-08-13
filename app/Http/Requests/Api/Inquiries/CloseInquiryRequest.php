<?php

namespace App\Http\Requests\Api\Inquiries;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-INQ-012, BR-016: closing an inquiry requires a mandatory resolution
 * summary.
 */
class CloseInquiryRequest extends FormRequest
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
            'resolution_summary' => ['required', 'string'],
        ];
    }
}
