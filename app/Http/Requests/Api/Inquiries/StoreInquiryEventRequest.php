<?php

namespace App\Http\Requests\Api\Inquiries;

use App\Http\Requests\Api\FormRequest;

/**
 * FR-INQ-008 / CLAUDE.md Section 8: only the 5 SDT-configured operational
 * event types are accepted from a client. InquiryEventType::StatusChanged
 * is system-generated only (InquiryService::transitionStatus()) and is
 * deliberately excluded here.
 */
class StoreInquiryEventRequest extends FormRequest
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
            'event_type' => ['required', 'string', 'in:hq_notified,referral_made,feedback_received,reminder_sent,follow_up_completed'],
            'note' => ['nullable', 'string'],
        ];
    }
}
