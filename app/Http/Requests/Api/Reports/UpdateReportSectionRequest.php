<?php

namespace App\Http\Requests\Api\Reports;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-RPT-006: auto-save of a narrative section's free-text content.
 */
class UpdateReportSectionRequest extends FormRequest
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
            'content' => ['nullable', 'string'],
        ];
    }
}
