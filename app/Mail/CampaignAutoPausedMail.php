<?php

namespace App\Mail;

use App\Enums\MailSender;
use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Platform ping: complaint auto-pause halted a campaign and paused its
 * restaurant's sending — a super admin needs to look.
 */
class CampaignAutoPausedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Campaign $campaign) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: MailSender::Service->address(),
            subject: "Campaign auto-paused: {$this->campaign->restaurant->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.campaign-auto-paused',
            with: [
                'campaign' => $this->campaign,
                'restaurant' => $this->campaign->restaurant,
                'reviewUrl' => route('admin.super.campaigns.index'),
            ],
        );
    }
}
