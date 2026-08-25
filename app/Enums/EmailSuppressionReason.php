<?php

namespace App\Enums;

enum EmailSuppressionReason: string
{
    case HardBounce = 'hard_bounce';
    case Complaint = 'complaint';
    case Manual = 'manual';
}
