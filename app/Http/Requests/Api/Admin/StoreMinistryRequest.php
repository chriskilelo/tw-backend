<?php

namespace App\Http\Requests\Api\Admin;

use App\Http\Requests\Api\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-006: a System Administrator onboards a new department, then appoints
 * its Ministry Administrator through POST /users.
 */
class StoreMinistryRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150', Rule::unique('ministries', 'name')],
        ];
    }
}
