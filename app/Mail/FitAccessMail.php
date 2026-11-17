<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Branded FitAccess email (black + green) with optional key/value details,
 * login credentials, "What now?" steps and a button. Build instances with
 * App\Services\ApplicationMailer rather than directly.
 */
class FitAccessMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string[]               $paragraphs
     * @param  array<string, string>  $details      label => value table
     * @param  array<string, string>  $credentials  label => value, shown in a dark box
     * @param  string[]               $steps        rendered as "Step 1: …"
     */
    public function __construct(
        public string $emailSubject,
        public string $heading,
        public ?string $recipientName = null,
        public array $paragraphs = [],
        public ?string $highlight = null,
        public array $details = [],
        public array $credentials = [],
        public array $steps = [],
        public ?string $buttonText = null,
        public ?string $buttonUrl = null,
        public ?string $closing = null,
        /** @var array<int, array{content: string, name: string, mime: string}> files attached to the email */
        public array $files = [],
        /** @var array<string, string> label => URL, shown as 'use the app' buttons */
        public array $appLinks = [],
        public ?string $appText = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->emailSubject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.fitaccess-branded', with: [
            'emailSubject'  => $this->emailSubject,
            'heading'       => $this->heading,
            'recipientName' => $this->recipientName,
            'paragraphs'    => $this->paragraphs,
            'highlight'     => $this->highlight,
            'details'       => $this->details,
            'credentials'   => $this->credentials,
            'steps'         => $this->steps,
            'buttonText'    => $this->buttonText,
            'buttonUrl'     => $this->buttonUrl,
            'closing'       => $this->closing,
            'appLinks'      => $this->appLinks,
            'appText'       => $this->appText,
        ]);
    }

    public function attachments(): array
    {
        return array_map(
            fn (array $f) => \Illuminate\Mail\Mailables\Attachment::fromData(fn () => $f['content'], $f['name'])->withMime($f['mime']),
            $this->files
        );
    }
}
