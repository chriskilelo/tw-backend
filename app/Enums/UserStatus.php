<?php

namespace App\Enums;

enum UserStatus: string
{
    case ActivationPending = 'activation_pending';
    case Active = 'active';
    case Locked = 'locked';
    case Deactivated = 'deactivated';
}
