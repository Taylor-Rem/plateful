<?php

namespace App\Enums;

enum MarketingConsentSource: string
{
    case Checkout = 'checkout';
    case Account = 'account';
    case UnsubscribeLink = 'unsubscribe_link';
    case Admin = 'admin';
}
