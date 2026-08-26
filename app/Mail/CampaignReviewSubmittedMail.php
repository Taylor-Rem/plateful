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
 * Platform ping: a restaurant's first campaign is waiting in the super-admin
 * review queue (campaigns plan, Session 3).
 */
class CampaignReviewSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Campaign $campaign) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: MailSender::Service->address(),
            subject: "Campaign review needed: {$this->campaign->restaurant->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.campaign-review-submitted',
            with: [
                'campaign' => $this->campaign,
                'restaurant' => $this->campaign->restaurant,
                'reviewUrl' => route('admin.super.campaigns.index'),
            ],
        );
    }
}
