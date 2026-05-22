<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case DriverAssigned = 'driver_assigned';
    case PickedUp = 'picked_up';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
}
