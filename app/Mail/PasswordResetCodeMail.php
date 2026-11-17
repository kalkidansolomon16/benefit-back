<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $resetCode,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your FitAccess Password Reset Code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password_reset_code',
        );
    }
}
