<?php

namespace App\Mail;

use App\Models\AdminInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public AdminInvitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You have been invited to Plateful',
        );
    }

    public function content(): Content
    {
        $scheme = app()->environment('production') ? 'https' : 'http';
        $host = 'admin.'.config('platform.primary_domain');
        $url = "{$scheme}://{$host}/invitations/{$this->invitation->token}";

        return new Content(
            view: 'emails.admin-invitation',
            with: [
                'inviterName' => $this->invitation->invitedBy?->name ?? 'A Plateful admin',
                'restaurantName' => $this->invitation->restaurant?->name,
                'acceptUrl' => $url,
                'asSuperAdmin' => $this->invitation->as_super_admin,
            ],
        );
    }
}
