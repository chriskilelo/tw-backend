<?php

namespace App\Http\Requests\Api\Referrals;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-REF-004: supporting document attached to a referral entry, subject to
 * configured type and size limits. Mirrors
 * App\Http\Requests\Api\Alerts\StoreAlertAttachmentRequest's NFR-SEC-004
 * (Session 38) allowlist — 10MB / jpg, jpeg, png, pdf, mp4, log only — and
 * its use of 'extensions' rather than 'mimes' for the same reason (see that
 * class's docblock). No dedicated SDT config screen for these limits exists
 * yet (FR-SDT-019).
 */
class StoreReferralAttachmentRequest extends FormRequest
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
