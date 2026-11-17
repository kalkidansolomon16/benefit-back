<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ResetUserMfa extends Command
{
    protected $signature = 'mfa:reset {email : Email of the account to reset two-factor auth for}';

    protected $description = 'Disable and clear two-factor authentication for a user (e.g. lost authenticator device with no recovery codes left)';

    public function handle(): int
    {
        $email = strtolower((string) $this->argument('email'));
        $user  = User::where('email', $email)->first();

        if (!$user) {
            $this->error("No user found with email {$email}.");
            return self::FAILURE;
        }

        if (!$user->google2fa_enabled && !$user->google2fa_secret) {
            $this->info("{$email} does not have two-factor authentication set up; nothing to reset.");
            return self::SUCCESS;
        }

        if (!$this->confirm("Disable two-factor authentication for {$email} ({$user->role})? They will be prompted to set it up again on next login.")) {
            $this->warn('Aborted.');
            return self::SUCCESS;
        }

        $user->forceFill([
            'google2fa_enabled'         => false,
            'google2fa_secret'          => null,
            'google2fa_recovery_codes'  => null,
        ])->save();

        $this->info("Two-factor authentication has been reset for {$email}.");
        return self::SUCCESS;
    }
}
