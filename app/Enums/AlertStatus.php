<?php

namespace App\Enums;

enum AlertStatus: string
{
    case New = 'new';
    case Assigned = 'assigned';
    case Acknowledged = 'acknowledged';
}
