<?php

namespace App\Mail;

use App\Models\RestaurantSignup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RestaurantSignupApprovedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public RestaurantSignup $signup) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Welcome to Plateful — {$this->signup->proposed_name} is approved",
        );
    }

    public function content(): Content
    {
        $scheme = app()->environment('production') ? 'https' : 'http';
        $adminUrl = "{$scheme}://admin.".config('platform.primary_domain');

        return new Content(
            view: 'emails.restaurant-signup-approved',
            with: [
                'signup' => $this->signup,
                'restaurantName' => $this->signup->proposed_name,
                'ownerName' => $this->signup->user?->name,
                'adminUrl' => $adminUrl,
            ],
        );
    }
}
