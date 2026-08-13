<?php

namespace App\Http\Requests\Api\Alerts;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-ALERT-003: supporting evidence file, subject to configured type and
 * size limits. 10MB / the document, spreadsheet, and image types listed
 * below match the limits used by the Session 21 frontend upload input
 * (25_TW_Stage1_Session_Prompts.md); no dedicated SDT config screen for
 * these limits exists yet (FR-SDT-019 is unbuilt), so they are hardcoded
 * here as the working default.
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
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
        ];
    }
}
