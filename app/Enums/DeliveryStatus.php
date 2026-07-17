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

    /**
     * Whether a courier has been assigned — i.e. the delivery is real and
     * someone is actually coming for it. This is the signal auth/capture (§8)
     * waits on before charging anyone, and it is provider-agnostic: the
     * per-provider status maps translate each network's vocabulary into these
     * cases, so both the webhooks and the deadline job ask the status itself
     * rather than any one provider's map.
     */
    public function hasCourier(): bool
    {
        return in_array($this, [
            self::DriverAssigned,
            self::PickedUp,
            self::Delivered,
        ], strict: true);
    }
}
