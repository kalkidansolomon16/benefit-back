<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestMail extends Command
{
    protected $signature = 'mail:test {email}';
    protected $description = 'Send a test email';

    public function handle(): void
    {
        $email = $this->argument('email');
        $this->info("Sending test email to {$email}...");

        try {
            Mail::raw('This is a test email from FitAccess. Mail is working correctly.', function ($m) use ($email) {
                $m->to($email)->subject('FitAccess Mail Test');
            });
            $this->info('Email sent successfully!');
        } catch (\Exception $e) {
            $this->error('Failed: ' . $e->getMessage());
        }
    }
}
