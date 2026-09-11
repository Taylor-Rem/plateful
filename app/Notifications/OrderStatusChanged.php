<?php

namespace App\Notifications;

use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A push to the customer's phone about one of their orders. Carries plain
 * values rather than the Order so a queued job never depends on the order
 * still being loadable; OrderNotifier decides the wording.
 */
class OrderStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $data  deep-link payload the app reads on tap
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $data,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [ExpoPushChannel::class];
    }

    /**
     * @return array{title: string, body: string, data: array<string, mixed>}
     */
    public function toExpo(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
        ];
    }
}
