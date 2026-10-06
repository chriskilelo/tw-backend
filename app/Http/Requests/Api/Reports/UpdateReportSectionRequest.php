<?php

namespace App\Http\Requests\Api\Reports;

use App\Http\Requests\Api\FormRequest;
use App\Models\ReportSection;
use App\Services\ReportService;
use Illuminate\Validation\Validator;

/**
 * FR-RPT-005/006 (API-001 Section 5): the auto-save of one section — either
 * a narrative section's `content`, or a structured table's `rows` in
 * display order ({id?, row_data}), which adds, edits, reorders and removes
 * rows in one request (FR-RPT-007). Row values are checked against the
 * section's column schema here, so every problem comes back in one
 * response (FR-RPT-010).
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
            'content' => ['prohibits:rows', 'nullable', 'string', 'max:'.ReportService::SECTION_CONTENT_MAX],
            'rows' => ['prohibits:content', 'sometimes', 'array'],
            'rows.*' => ['array'],
            'rows.*.id' => ['nullable', 'uuid'],
            'rows.*.row_data' => ['present', 'array'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->has('content') && ! $this->has('rows')) {
                    $validator->errors()->add('content', 'Send the section content, or its rows for a table section.');
                }
            },
            function (Validator $validator): void {
                $section = $this->route('section');

                if ($validator->errors()->isNotEmpty() || ! $section instanceof ReportSection || ! $this->has('rows')) {
                    return;
                }

                foreach (app(ReportService::class)->sectionRowErrors($section->reportTemplateSection, (array) $this->input('rows', [])) as $error) {
                    $validator->errors()->add('rows', $error);
                }
            },
        ];
    }

    public function isRowsUpdate(): bool
    {
        return $this->has('rows');
    }
}
