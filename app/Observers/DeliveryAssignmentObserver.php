<?php

namespace App\Observers;

use App\Enums\DeliveryStatus;
use App\Models\DeliveryAssignment;
use App\Services\OrderNotifier;

/**
 * Courier milestones reach the customer from here, whichever writer moved
 * the status (either courier's webhook, a status poll, or a cancel).
 */
class DeliveryAssignmentObserver
{
    public function __construct(protected OrderNotifier $notifier) {}

    public function updated(DeliveryAssignment $assignment): void
    {
        if (! $assignment->wasChanged('status') || ! $assignment->status instanceof DeliveryStatus) {
            return;
        }

        $this->notifier->deliveryChanged($assignment, $assignment->status);
    }
}
