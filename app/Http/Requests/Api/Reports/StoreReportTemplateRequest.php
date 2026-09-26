<?php

namespace App\Http\Requests\Api\Reports;

use App\Http\Requests\Api\Concerns\PinsDepartmentForMinistryAdministrator;
use App\Http\Requests\Api\FormRequest;

/**
 * BR-006: creates a new template version. `version` is never client
 * supplied — App\Services\ReportService::createTemplateVersion() always
 * derives the next sequential version number, so a prior version's rows can
 * never be overwritten.
 */
class StoreReportTemplateRequest extends FormRequest
{
    use PinsDepartmentForMinistryAdministrator;

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
            'ministry_id' => $this->departmentRules(),
            'effective_date' => ['required', 'date'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.section_order' => ['required', 'integer', 'min:1'],
            'sections.*.section_title' => ['required', 'string', 'max:255'],
            'sections.*.section_type' => ['required', 'string', 'in:narrative,structured_table'],
            'sections.*.column_schema' => ['nullable', 'array'],
            'sections.*.column_schema.*.name' => ['required_with:sections.*.column_schema', 'string'],
            'sections.*.column_schema.*.type' => ['required_with:sections.*.column_schema', 'string'],
            'sections.*.column_schema.*.mandatory' => ['sometimes', 'boolean'],
            'sections.*.guidance_text' => ['nullable', 'string'],
        ];
    }
}
