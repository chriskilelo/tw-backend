<?php

namespace App\Http\Requests\Api\Directives;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-DIR-008, FR-DIR-011: append-only progress/follow-up notes.
 */
class StoreDirectiveNoteRequest extends FormRequest
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
            'content' => ['required', 'string'],
        ];
    }
}
