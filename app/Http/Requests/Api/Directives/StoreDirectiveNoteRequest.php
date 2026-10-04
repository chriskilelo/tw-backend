<?php

namespace App\Http\Requests\Api\Directives;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-DIR-008, FR-DIR-011: append-only progress/follow-up notes, by the
 * target attache or the issuer only (DirectivePolicy::addNote(), checked
 * before validation).
 */
class StoreDirectiveNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('addNote', $this->route('directive')) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required' => 'Write a note before posting it.',
            'content.max' => 'The note may not be longer than 5000 characters.',
        ];
    }
}
