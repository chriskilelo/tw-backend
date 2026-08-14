<?php

namespace App\Http\Requests\Api\Inquiries;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-INQ-019. target_inquiry_id is nullable by design: omitting it makes
 * InquiryController::link() return suggested matches only (AC1); supplying
 * it confirms and persists a link (AC2). The exists rule checks the raw
 * table (no ministry-scope awareness), so a cross-ministry id still passes
 * validation — the actual guard is Inquiry's own ministry-scoped global
 * scope in the controller (findOrFail 404s it), matching the IDOR-guard
 * pattern established for report data rows and KPI targets.
 */
class LinkInquiryRequest extends FormRequest
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
            'target_inquiry_id' => ['nullable', 'uuid', 'exists:inquiries,id'],
        ];
    }
}
