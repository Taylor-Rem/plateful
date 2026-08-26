<?php

namespace App\Enums;

enum MarketingConsentAction: string
{
    case OptedIn = 'opted_in';
    case OptedOut = 'opted_out';
}
