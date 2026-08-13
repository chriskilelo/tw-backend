<?php

namespace App\Enums;

enum ContentStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case PublicationReady = 'publication_ready';
}
