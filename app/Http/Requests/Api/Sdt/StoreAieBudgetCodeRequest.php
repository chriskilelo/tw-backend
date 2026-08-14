<?php

namespace App\Http\Requests\Api\Sdt;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-SDT-022: adds a row to the 'aie_budget_code' master_data_entries
 * category (CLAUDE.md Section 8 AIE Allocations Table Schema). category is
 * not a client-supplied field — App\Http\Controllers\Api\Sdt\ConfigController
 * forces it, since this endpoint only ever manages that one category,
 * matching StoreAlertFieldRequest's precedent.
 */
class StoreAieBudgetCodeRequest extends FormRequest
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
            'ministry_id' => ['required', 'uuid', 'exists:ministries,id'],
            'value' => ['required', 'string', 'max:255'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
