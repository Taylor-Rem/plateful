<?php

namespace App\Contracts;

use App\Enums\DeliveryProviderName;
use App\Models\DeliveryAssignment;
use App\Models\Order;
use App\Models\Restaurant;
use App\Services\Delivery\DeliveryQuote;
use App\Services\Delivery\DeliveryQuoteRequest;

interface DeliveryProvider
{
    public function name(): DeliveryProviderName;

    public function supports(Restaurant $restaurant): bool;

    public function quote(DeliveryQuoteRequest $request): DeliveryQuote;

    public function create(Order $order, DeliveryQuote $quote): DeliveryAssignment;

    public function status(DeliveryAssignment $assignment): DeliveryAssignment;

    public function cancel(DeliveryAssignment $assignment): void;
}
