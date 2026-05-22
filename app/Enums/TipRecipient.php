<?php

namespace App\Enums;

use App\Models\Restaurant;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum TipRecipient: string
{
    case Pool = 'pool';
    case Driver = 'driver';
    case Split = 'split';

    /**
     * Decide where a tip should be allocated for an order placed at the given
     * restaurant with the given order type. This is the source of truth — both
     * OrderPlacement and the backfill use this.
     */
    public static function forOrder(Restaurant $restaurant, OrderType $type): self
    {
        if ($type !== OrderType::Delivery) {
            return self::Pool;
        }

        if ($restaurant->delivery_mode === DeliveryMode::SelfDelivery) {
            return match ($restaurant->self_delivery_tip_recipient) {
                SelfDeliveryTipRecipient::Pool => self::Pool,
                SelfDeliveryTipRecipient::Split5050 => self::Split,
                default => self::Driver,
            };
        }

        // Third-party delivery (or unconfigured-but-delivery): tip goes to the driver.
        return self::Driver;
    }
}
