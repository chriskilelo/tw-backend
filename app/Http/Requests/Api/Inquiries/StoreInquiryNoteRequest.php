<?php

namespace App\Http\Requests\Api\Inquiries;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-INQ-014: append-only progress notes.
 */
class StoreInquiryNoteRequest extends FormRequest
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
            'content' => ['required', 'string'],
        ];
    }
}
