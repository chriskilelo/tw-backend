<?php

namespace App\Http\Requests\Api\Sdt;

use App\Http\Controllers\Api\Sdt\ConfigController;
use App\Http\Requests\Api\Concerns\PinsDepartmentForMinistryAdministrator;
use App\Http\Requests\Api\FormRequest;

/**
 * FR-SDT-020: adds a configured inquiry category, workflow status, or
 * operational event type. category must be one of the three
 * master_data_entries categories this screen administers — transitions
 * themselves are not separately configurable data (CLAUDE.md Section 7's
 * workflow table is currently hardcoded in InquiryService, out of this
 * session's scope).
 */
class StoreInquirySettingRequest extends FormRequest
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
            'category' => ['required', 'string', 'in:'.implode(',', ConfigController::INQUIRY_SETTING_CATEGORIES)],
            'ministry_id' => $this->departmentRules(),
            'value' => ['required', 'string', 'max:255'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
