<?php

namespace App\Http\Requests\Api\Admin;

use App\Http\Requests\Api\FormRequest;
use App\Services\AdministrationService;
use Illuminate\Validation\Rule;

/**
 * FR-MDATA-002: an authorised administrator edits or deactivates a shared
 * master data entry. category is deliberately not editable here — changing
 * it would silently move the entry into a different dropdown; delete and
 * recreate instead.
 */
class UpdateMasterDataEntryRequest extends FormRequest
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
            // ADR-006: a Ministry Administrator cannot move an entry to another department.
            'ministry_id' => [Rule::prohibitedIf(fn (): bool => AdministrationService::isMinistryAdministrator($this->user())), 'sometimes', 'nullable', 'uuid', 'exists:ministries,id'],
            'value' => ['sometimes', 'string', 'max:255'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
