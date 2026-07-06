<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FitAccessNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $emailSubject,
        public string $heading,
        public string $message,
        public string $buttonText = 'Log In to FitAccess',
        public ?string $buttonUrl = null,
        public string $color = '#22c55e',  // green = approved, red = rejected, blue = info
    ) {
        $this->buttonUrl = $buttonUrl ?? env('FRONTEND_URL', 'http://localhost:5173') . '/login';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->emailSubject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.fitaccess-notification', with: [
            'recipientName' => $this->recipientName,
            'heading'       => $this->heading,
            'message'       => $this->message,
            'buttonText'    => $this->buttonText,
            'buttonUrl'     => $this->buttonUrl,
            'color'         => $this->color,
        ]);
    }

    public function attachments(): array { return []; }
}
