<?php

namespace App\Http\Requests\Api\Sdt;

use App\Http\Requests\Api\Concerns\PinsDepartmentForMinistryAdministrator;
use App\Http\Requests\Api\FormRequest;

/**
 * FR-SDT-019: adds a configured alert field option to the
 * 'alert_intelligence_type' master_data_entries category. category is not
 * a client-supplied field — App\Http\Controllers\Api\Sdt\ConfigController
 * forces it, since this endpoint only ever manages that one category.
 */
class StoreAlertFieldRequest extends FormRequest
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
            'value' => ['required', 'string', 'max:255'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
