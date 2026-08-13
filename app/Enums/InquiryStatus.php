<?php

namespace App\Enums;

enum InquiryStatus: string
{
    case Draft = 'draft';
    case Received = 'received';
    case InProgress = 'in_progress';
    case PendingExternalResponse = 'pending_external_response';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}
