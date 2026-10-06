<?php

namespace App\Http\Requests\Api\Reports;

use App\Http\Requests\Api\Concerns\PinsDepartmentForMinistryAdministrator;
use App\Http\Requests\Api\FormRequest;

/**
 * BR-006: creates a new template version. `version` is never client
 * supplied — App\Services\ReportService::createTemplateVersion() always
 * derives the next sequential version number, so a prior version's rows can
 * never be overwritten.
 *
 * A structured table section may list `options` for a selection column and
 * a `table_config` (pre-populated rows from a master data category,
 * FR-RPT-008; an auto-calculated total row, FR-RPT-009; a row maximum,
 * FR-RPT-007).
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
            'sections.*.column_schema.*.options' => ['sometimes', 'nullable', 'array'],
            'sections.*.column_schema.*.options.*' => ['string', 'max:255'],
            'sections.*.table_config' => ['sometimes', 'nullable', 'array'],
            'sections.*.table_config.prepopulate' => ['sometimes', 'nullable', 'array'],
            'sections.*.table_config.prepopulate.master_data_category' => ['required_with:sections.*.table_config.prepopulate', 'string', 'max:100'],
            'sections.*.table_config.prepopulate.label_columns' => ['required_with:sections.*.table_config.prepopulate', 'array', 'min:1'],
            'sections.*.table_config.prepopulate.label_columns.*' => ['string'],
            'sections.*.table_config.total' => ['sometimes', 'nullable', 'array'],
            'sections.*.table_config.total.label' => ['sometimes', 'string', 'max:50'],
            'sections.*.table_config.total.label_column' => ['required_with:sections.*.table_config.total', 'string'],
            'sections.*.table_config.total.sum_columns' => ['required_with:sections.*.table_config.total', 'array', 'min:1'],
            'sections.*.table_config.total.sum_columns.*' => ['string'],
            'sections.*.table_config.total.exclude_labels' => ['sometimes', 'array'],
            'sections.*.table_config.total.exclude_labels.*' => ['string'],
            'sections.*.table_config.max_rows' => ['sometimes', 'integer', 'min:20', 'max:1000'],
            'sections.*.guidance_text' => ['nullable', 'string'],
        ];
    }
}
