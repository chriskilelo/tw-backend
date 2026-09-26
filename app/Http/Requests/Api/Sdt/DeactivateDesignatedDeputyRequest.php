<?php

namespace App\Http\Requests\Api\Sdt;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-SDT-003: switches the Designated Deputy fallback off. ministry_id is
 * only needed from a System Administrator, who has no department of its own.
 */
class DeactivateDesignatedDeputyRequest extends FormRequest
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
            'ministry_id' => ['sometimes', 'uuid', 'exists:ministries,id'],
        ];
    }
}
