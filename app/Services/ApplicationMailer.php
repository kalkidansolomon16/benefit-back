<?php

namespace App\Services;

use App\Mail\FitAccessMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails for the "apply → admin verifies → account with temporary password" flow
 * used by companies (employers) and gyms (partners). Every send is isolated so a
 * mail failure is logged and never breaks the request that triggered it.
 */
class ApplicationMailer
{
    public const COMPANY = 'company';
    public const PARTNER = 'partner';

    private static function frontendUrl(string $path = ''): string
    {
        return config('app.frontend_url') . $path;
    }

    private static function send(string $to, FitAccessMail $mail, string $context): bool
    {
        try {
            Mail::to($to)->send($mail);
            return true;
        } catch (\Throwable $e) {
            Log::error("Application email failed ({$context}): " . $e->getMessage());
            return false;
        }
    }

    /** To the applicant, right after they submit the form. */
    public static function received(string $kind, string $name, string $email, string $reference, string $orgName): bool
    {
        $isCompany = $kind === self::COMPANY;

        $mail = new FitAccessMail(
            emailSubject: "Your FitAccess application has been registered under number {$reference}",
            heading: 'Thank you for your application!',
            recipientName: $name,
            highlight: "Your application has been registered under number {$reference}",
            paragraphs: [
                $isCompany
                    ? "Thank you for submitting the application for {$orgName}. Our team will now verify the information you provided, including your TIN."
                    : "Thank you for applying to become a FitAccess partner with {$orgName}. Our team will now verify that your facility meets our terms of cooperation.",
            ],
            steps: $isCompany
                ? [
                    'Our team will contact you within the next 2 business days to discuss the details and answer any questions you may have.',
                    'After verifying your company, we will create your FitAccess account.',
                    'You will receive an email with your login details and a temporary password. You will set your own password when you first sign in.',
                ]
                : [
                    'In the coming days we will review your application and contact you within 3 business days.',
                    'Once your facility is approved, we will create your partner account.',
                    'You will receive an email with your login details and a temporary password. You will set your own password when you first sign in.',
                ],
            closing: 'Please keep your application number for any questions. Thank you for your patience and trust!',
        );

        return self::send($email, $mail, "received {$reference}");
    }

    /**
     * To the FitAccess notification inbox(es) and every active admin account.
     *
     * @param  array<string, string>  $details
     */
    public static function notifyAdmins(string $kind, string $reference, string $orgName, array $details): void
    {
        $isCompany = $kind === self::COMPANY;

        // email => name; the configured inbox(es) first, then admin accounts, each address once
        $recipients = [];
        foreach (config('fitaccess.admin_notification_emails', []) as $email) {
            $recipients[strtolower($email)] = 'FitAccess team';
        }
        User::whereIn('role', ['super_admin', 'fitaccess_admin'])
            ->where('is_active', true)
            ->whereNotNull('email')
            ->get(['name', 'email'])
            ->each(function (User $u) use (&$recipients) {
                $recipients[strtolower($u->email)] ??= $u->name;
            });

        foreach ($recipients as $email => $name) {
            $admin = (object) ['name' => $name, 'email' => $email];
            $mail = new FitAccessMail(
                emailSubject: ($isCompany ? 'New employer application: ' : 'New partner application: ') . "{$orgName} ({$reference})",
                heading: $isCompany ? 'New employer application' : 'New partner application',
                recipientName: $admin->name,
                highlight: "Application {$reference}",
                paragraphs: [
                    "{$orgName} has applied to join FitAccess. No account has been created yet.",
                    'Please verify the details and approve or reject the application in the admin panel.',
                ],
                details: $details,
                buttonText: 'Review application',
                buttonUrl: self::frontendUrl($isCompany ? '/admin/company-applications' : '/admin/gyms'),
            );
            self::send($admin->email, $mail, "admin notice {$reference}");
        }
    }

    /** Login details for a newly approved account or branch login. */
    public static function credentials(string $kind, string $name, string $email, string $tempPassword, string $accountLabel): bool
    {
        $isCompany = $kind === self::COMPANY;

        $mail = new FitAccessMail(
            emailSubject: 'Your FitAccess account is ready',
            heading: 'Welcome to FitAccess!',
            recipientName: $name,
            paragraphs: [
                $isCompany
                    ? "Your application has been approved and the FitAccess account for {$accountLabel} has been created."
                    : "Your partner application has been approved and the FitAccess partner account for {$accountLabel} has been created.",
                'Sign in with the details below. For your security you will be asked to choose your own password the first time you sign in.',
            ],
            credentials: [
                'Login email'        => $email,
                'Temporary password' => $tempPassword,
            ],
            buttonText: 'Sign in to FitAccess',
            buttonUrl: self::frontendUrl('/login'),
            closing: 'Do not share this password with anyone. If you did not expect this email, please contact us.',
        );

        return self::send($email, $mail, "credentials {$email}");
    }

    /** To the applicant when the admin rejects the application. */
    public static function rejected(string $kind, string $name, string $email, string $reference, string $orgName, ?string $reason): bool
    {
        $paragraphs = [
            ($kind === self::COMPANY
                ? "Thank you for your interest in FitAccess. After verification, we are unable to approve the application for {$orgName} at this time."
                : "Thank you for your interest in becoming a FitAccess partner. After review, we are unable to approve the application for {$orgName} at this time."),
        ];
        if ($reason) {
            $paragraphs[] = "Reason: {$reason}";
        }
        $paragraphs[] = 'If you believe this is a mistake or your situation changes, you are welcome to reply to this email or apply again.';

        $mail = new FitAccessMail(
            emailSubject: "Your FitAccess application {$reference}",
            heading: 'About your application',
            recipientName: $name,
            highlight: "Application {$reference}",
            paragraphs: $paragraphs,
        );

        return self::send($email, $mail, "rejected {$reference}");
    }
}
