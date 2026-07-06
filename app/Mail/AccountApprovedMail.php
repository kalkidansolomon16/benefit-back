<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your FitAccess Account Has Been Approved',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account-approved',
            with: [
                'name'     => $this->user->name,
                'email'    => $this->user->email,
                'loginUrl' => env('FRONTEND_URL', 'http://localhost:5173') . '/login',
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
