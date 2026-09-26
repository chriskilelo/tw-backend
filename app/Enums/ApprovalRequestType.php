<?php

namespace App\Enums;

/**
 * FR-AUTH-022/023: the four Principal Secretary changes a Ministry
 * Administrator may request and a System Administrator must approve (BR-027).
 */
enum ApprovalRequestType: string
{
    case PsAppointment = 'ps_appointment';
    case PsPromotion = 'ps_promotion';
    case PsDeactivation = 'ps_deactivation';
    case PsSuccession = 'ps_succession';
}
