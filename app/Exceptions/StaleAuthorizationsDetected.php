<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised (via report(), never thrown) when orders have sat in the Authorized
 * payment state past the point where ExpireAuthorizedDelivery should have
 * captured or released the hold. That only happens when the queue worker is
 * dead or the expiry job keeps failing — either way a customer's funds are
 * stranded and a human needs to look. A dedicated class so Sentry groups
 * every occurrence into one issue with one alert rule.
 */
class StaleAuthorizationsDetected extends RuntimeException
{
    /**
     * @param  list<int>  $orderIds
     */
    public function __construct(
        public readonly int $count,
        public readonly int $olderThanMinutes,
        public readonly array $orderIds,
    ) {
        parent::__construct(sprintf(
            '%d order%s still authorized after %d minutes — the queue worker or ExpireAuthorizedDelivery is not running (order ids: %s)',
            $count,
            $count === 1 ? '' : 's',
            $olderThanMinutes,
            implode(', ', $orderIds),
        ));
    }
}
