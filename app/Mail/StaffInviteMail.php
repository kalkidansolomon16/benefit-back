<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $gymName,
        public readonly string $email,
        public readonly string $tempPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You've been added as staff at {$this->gymName} — FitAccess",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff_invite',
        );
    }
}
