<?php

namespace App\Mail;

use App\Models\RestaurantSignup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RestaurantSignupRejectedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public RestaurantSignup $signup) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Update on your Plateful application',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.restaurant-signup-rejected',
            with: [
                'restaurantName' => $this->signup->proposed_name,
                'ownerName' => $this->signup->user?->name,
                'reason' => $this->signup->rejection_reason,
            ],
        );
    }
}
