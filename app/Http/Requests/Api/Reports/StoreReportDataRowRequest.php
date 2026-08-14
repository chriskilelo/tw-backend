<?php

namespace App\Http\Requests\Api\Reports;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-RPT-007: adds a row to a structured_table section. Column-key and
 * mandatory-field validation against the section's column_schema happens in
 * App\Services\ReportService::addDataRow(), not here — that schema is
 * per-section and configured data, not something a static rule set can
 * express.
 */
class StoreReportDataRowRequest extends FormRequest
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
            'section_id' => ['required', 'uuid', 'exists:report_sections,id'],
            'row_data' => ['required', 'array'],
            'row_order' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
