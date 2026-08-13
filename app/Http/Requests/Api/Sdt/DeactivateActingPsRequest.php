<?php

namespace App\Http\Requests\Api\Sdt;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-SDT-006: ministry_id is optional — a Ministry PS deactivating their
 * own ministry's Acting PS assignment needs no body at all
 * (App\Http\Controllers\Api\Sdt\ActingPsController defaults to the
 * caller's own ministry_id); a System Administrator, who has none, must
 * supply it explicitly.
 */
class DeactivateActingPsRequest extends FormRequest
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
