<?php

namespace App\Http\Requests\Api\Inquiries;

use App\Enums\InquiryStatus;
use App\Http\Requests\Api\FormRequest;

/**
 * FR-INQ-006: the target status must be one of the configured workflow
 * statuses (CLAUDE.md Section 7); whether the transition is actually legal
 * from the inquiry's current status is validated by
 * InquiryService::transitionStatus() (depends on model state, not just the
 * request payload).
 */
class UpdateInquiryStatusRequest extends FormRequest
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
            'status' => ['required', 'string', 'in:'.implode(',', array_column(InquiryStatus::cases(), 'value'))],
        ];
    }
}
