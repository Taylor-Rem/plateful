<?php

namespace App\Mail;

use App\Models\RestaurantSignup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RestaurantSignupSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public RestaurantSignup $signup) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New restaurant signup: '.$this->signup->proposed_name,
        );
    }

    public function content(): Content
    {
        $scheme = app()->environment('production') ? 'https' : 'http';
        $reviewUrl = "{$scheme}://admin.".config('platform.primary_domain')."/super/signups/{$this->signup->id}";

        return new Content(
            view: 'emails.restaurant-signup-submitted',
            with: [
                'signup' => $this->signup,
                'reviewUrl' => $reviewUrl,
            ],
        );
    }
}
