<?php

namespace App\Enums;

use Illuminate\Mail\Mailables\Address;

/**
 * The outgoing alias an email is sent from. Every Mailable's envelope picks
 * one; framework-generated mail (password reset, email verification) uses the
 * global mail.from address, which is the service alias in production.
 */
enum MailSender: string
{
    case Orders = 'orders';
    case Service = 'service';
    case Support = 'support';

    public function address(): Address
    {
        return new Address(
            (string) config("mail.senders.{$this->value}"),
            (string) config('mail.from.name'),
        );
    }
}
