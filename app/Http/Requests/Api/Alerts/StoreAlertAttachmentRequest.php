<?php

namespace App\Http\Requests\Api\Alerts;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-ALERT-003: supporting evidence file, subject to configured type and
 * size limits. NFR-SEC-004 (Session 38, pre-audit hardening) narrowed the
 * allowlist to exactly jpg, jpeg, png, pdf, mp4, log, superseding the
 * broader pdf/doc/docx/xls/xlsx/png/jpg/jpeg set Session 10/21 hardcoded as
 * a working default — no dedicated SDT config screen for these limits
 * exists yet (FR-SDT-019 is unbuilt), so they remain hardcoded here.
 * 'extensions' (not 'mimes') validates the literal file extension and
 * additionally blocks disguised PHP uploads regardless of extension
 * (Illuminate\Validation\Concerns\ValidatesAttributes::shouldBlockPhpUpload);
 * 'mimes' was dropped here since content-sniffed MIME detection for a
 * '.log' plain-text file is unreliable across environments and could
 * reject a legitimate upload the allowlist is meant to permit.
 */
class StoreAlertAttachmentRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:10240', 'extensions:jpg,jpeg,png,pdf,mp4,log'],
        ];
    }
}
