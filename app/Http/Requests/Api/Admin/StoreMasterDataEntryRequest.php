<?php

namespace App\Http\Requests\Api\Admin;

use App\Http\Requests\Api\Concerns\PinsDepartmentForMinistryAdministrator;
use App\Http\Requests\Api\FormRequest;

/**
 * FR-MDATA-002: an authorised administrator adds a shared master data
 * entry. ministry_id is nullable (platform-wide entries, e.g. countries)
 * per CLAUDE.md Section 6's master_data_entries column notes.
 */
class StoreMasterDataEntryRequest extends FormRequest
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
            'ministry_id' => $this->departmentRules(required: false),
            'category' => ['required', 'string', 'max:100'],
            'value' => ['required', 'string', 'max:255'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
