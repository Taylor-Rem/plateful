<?php

use App\Contracts\PosProvider;
use App\Enums\PosProviderName;
use App\Models\Order;
use App\Models\PosIntegration;
use App\Models\Restaurant;
use App\Services\Pos\PosPushResult;

if (! function_exists('fakePosProvider')) {
    function fakePosProvider(
        PosProviderName $name = PosProviderName::Square,
        bool $supports = true,
        ?Throwable $throwOnPush = null,
        string $ticketId = 'SQ-123',
    ): PosProvider {
        return new class($name, $supports, $throwOnPush, $ticketId) implements PosProvider
        {
            public function __construct(
                private PosProviderName $n,
                private bool $supports,
                private ?Throwable $throwOnPush,
                private string $ticketId,
            ) {}

            public function name(): PosProviderName
            {
                return $this->n;
            }

            public function supports(Restaurant $r): bool
            {
                return $this->supports;
            }

            public function pushOrder(Order $order, PosIntegration $integration): PosPushResult
            {
                if ($this->throwOnPush) {
                    throw $this->throwOnPush;
                }

                return PosPushResult::ok($this->n, $this->ticketId);
            }
        };
    }
}
