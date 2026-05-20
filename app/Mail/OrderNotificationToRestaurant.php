<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderNotificationToRestaurant extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "New order #{$this->order->number}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-notification',
            with: [
                'order' => $this->order,
                'restaurant' => $this->order->restaurant,
            ],
        );
    }
}
