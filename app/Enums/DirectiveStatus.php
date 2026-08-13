<?php

namespace App\Enums;

enum DirectiveStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Acknowledged = 'acknowledged';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Closed = 'closed';
}
