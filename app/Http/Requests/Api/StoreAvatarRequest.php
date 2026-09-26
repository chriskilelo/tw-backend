<?php

namespace App\Http\Requests\Api;

/**
 * FR-AUTH-019: optional self-uploaded profile photo. Narrower allowlist than
 * StoreAlertAttachmentRequest (jpg/jpeg/png only, no pdf/mp4/log — an avatar is always
 * an image) and a 2MB cap, matching the size this feature was specified against.
 * 'extensions' (not 'mimes'), same reasoning as StoreAlertAttachmentRequest: validates
 * the literal file extension and blocks disguised PHP uploads regardless of extension.
 */
class StoreAvatarRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:2048', 'extensions:jpg,jpeg,png'],
        ];
    }
}
