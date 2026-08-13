<?php

namespace App\Http\Requests\Api\Admin;

use App\Http\Requests\Api\FormRequest;

class StoreMissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'city' => ['required', 'string', 'max:100'],
            'host_country' => ['required', 'string', 'max:100'],
            'time_zone' => ['required', 'string', 'max:50'],
        ];
    }
}
