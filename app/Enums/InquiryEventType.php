<?php

namespace App\Enums;

enum InquiryEventType: string
{
    case HqNotified = 'hq_notified';
    case ReferralMade = 'referral_made';
    case FeedbackReceived = 'feedback_received';
    case ReminderSent = 'reminder_sent';
    case FollowUpCompleted = 'follow_up_completed';

    /**
     * System-generated only (InquiryService::transitionStatus()), not part
     * of CLAUDE.md Section 8's 5 configured operational event types and
     * deliberately excluded from StoreInquiryEventRequest's accepted values.
     */
    case StatusChanged = 'status_changed';
}
