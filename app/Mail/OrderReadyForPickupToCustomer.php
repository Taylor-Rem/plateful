<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderReadyForPickupToCustomer extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        $restaurantName = $this->order->restaurant?->name ?? 'Plateful';

        return new Envelope(
            subject: "Your order is ready - {$restaurantName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order-ready-pickup',
            with: [
                'order' => $this->order,
                'restaurant' => $this->order->restaurant,
            ],
        );
    }
}
