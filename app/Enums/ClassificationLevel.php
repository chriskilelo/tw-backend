<?php

namespace App\Enums;

enum ClassificationLevel: string
{
    case Public = 'public';
    case InternalUseOnly = 'internal_use_only';
    case Restricted = 'restricted';
    case Confidential = 'confidential';
}
