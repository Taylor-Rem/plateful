<?php

namespace App\Contracts;

use App\Enums\PosProviderName;
use App\Models\Order;
use App\Models\PosIntegration;
use App\Models\Restaurant;
use App\Services\Pos\PosPushResult;

interface PosProvider
{
    public function name(): PosProviderName;

    public function supports(Restaurant $restaurant): bool;

    public function pushOrder(Order $order, PosIntegration $integration): PosPushResult;
}
