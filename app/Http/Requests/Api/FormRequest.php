<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest as BaseFormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base FormRequest for the /api/v1 surface. Ensures validation failures
 * still honour the {data, errors} envelope (CLAUDE.md Section 10) instead
 * of Laravel's default {message, errors} shape.
 */
abstract class FormRequest extends BaseFormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'data' => null,
            'errors' => collect($validator->errors()->messages())->flatten()->all(),
        ], 422));
    }
}
