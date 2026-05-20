<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderCancelledToCustomer extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order, public ?string $reason = null) {}

    public function envelope(): Envelope
    {
        $restaurantName = $this->order->restaurant?->name ?? 'Plateful';

        return new Envelope(
            subject: "Order #{$this->order->number} cancelled - {$restaurantName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-cancelled',
            with: [
                'order' => $this->order,
                'restaurant' => $this->order->restaurant,
                'reason' => $this->reason,
            ],
        );
    }
}
