<?php

namespace App\Enums;

enum InquirySubType: string
{
    case Standard = 'standard';
    case DisputeOrComplaint = 'dispute_or_complaint';
}
