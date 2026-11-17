<?php

namespace App\Services;

use App\Mail\FitAccessMail;
use App\Models\AdminNotification;
use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * One place for "tell someone something happened": every notice is saved in
 * the person's portal (bell + pop-up after sign-in) and sent by email.
 *
 * The `notifications` table is shared with the mobile app, so the stored
 * `type` stays one of its existing values; our own kind goes in data.kind.
 * A failure to email never breaks the action that triggered it.
 *
 * $mail keys (all optional): subject, heading, paragraphs, highlight, details,
 * steps, buttonText, buttonUrl, closing, files.
 */
class Notifier
{
    public const HR_ROLES = ['company_hr', 'co_hr', 'co_executive', 'co_finance', 'company_finance', 'company_ceo'];

    /** $mail = null: portal notice only (the email is sent elsewhere) */
    public static function user(User $user, string $kind, string $title, string $body, ?array $mail = [], array $data = []): void
    {
        self::store($user, $kind, $title, $body, $data + ['link' => $mail['buttonUrl'] ?? null]);
        if ($user->email && $mail !== null) {
            self::mail($user->email, $user->name, $title, $body, $mail);
        }
    }

    /**
     * Notify a company: every active HR/finance account (head office, or that
     * branch for a branch matter) gets the portal notice; the billing contacts
     * get the email.
     */
    public static function company(Company $company, string $kind, string $title, string $body, ?array $mail = [], ?int $branchId = null, array $data = []): void
    {
        $users = User::where('is_active', true)
            ->whereIn('role', self::HR_ROLES)
            ->where(fn ($q) => $q->where('company_id', $company->id)->orWhere('email', $company->contact_email))
            ->where(fn ($q) => $branchId
                ? $q->whereNull('company_branch_id')->orWhere('company_branch_id', $branchId)
                : $q->whereNull('company_branch_id'))
            ->get();

        foreach ($users as $u) {
            self::store($u, $kind, $title, $body, $data + ['link' => $mail['buttonUrl'] ?? null, 'company_id' => $company->id]);
        }

        if ($mail === null) {
            return;
        }
        foreach (BillingMailer::companyRecipients($company, $branchId) as $email => $name) {
            self::mail($email, $name, $title, $body, $mail);
        }
    }

    /**
     * Notify a partner gym: the owner and its finance/management accounts get
     * the portal notice; the owner and the gym contact e-mail get the email.
     */
    public static function gym(\App\Models\Gym $gym, string $kind, string $title, string $body, ?array $mail = [], array $data = []): void
    {
        $users = User::where('is_active', true)
            ->where(fn ($q) => $q->where('id', $gym->owner_user_id)
                ->orWhere(fn ($q2) => $q2->where('gym_id', $gym->id)->whereIn('role', ['gym_partner', 'gym_finance', 'gym_executive']))
                ->orWhere('email', $gym->contact_email))
            ->get();

        foreach ($users as $u) {
            self::store($u, $kind, $title, $body, $data + ['link' => $mail['buttonUrl'] ?? null, 'gym_id' => $gym->id]);
        }
        if ($mail === null) {
            return;
        }
        $to = [];
        foreach ($users->whereIn('role', ['gym_partner', 'gym_finance']) as $u) {
            $to[strtolower($u->email)] = $u->name;
        }
        if ($gym->contact_email) {
            $to[strtolower($gym->contact_email)] ??= $gym->contact_person ?: $gym->name;
        }
        foreach ($to as $email => $name) {
            self::mail($email, $name, $title, $body, $mail);
        }
    }

    /** FitAccess team: admin bell (+ the admin notification inboxes by email when asked) */
    public static function admins(string $kind, string $title, string $message, array $data = [], bool $email = false): void
    {
        try {
            AdminNotification::create(['type' => $kind, 'title' => $title, 'message' => $message, 'data' => $data, 'created_at' => now()]);
        } catch (\Throwable $e) {
            Log::error("Admin notification failed ({$kind}): " . $e->getMessage());
        }
        if ($email) {
            foreach (config('fitaccess.admin_notification_emails', []) as $to) {
                self::mail($to, 'FitAccess team', $title, $message, ['buttonText' => 'Open admin portal', 'buttonUrl' => config('app.frontend_url') . '/admin']);
            }
        }
    }

    private static function store(User $user, string $kind, string $title, string $body, array $data): void
    {
        try {
            Notification::create([
                'user_id' => $user->id,
                'title'   => mb_substr($title, 0, 250),
                'body'    => $body,
                'type'    => str_starts_with($kind, 'invoice') || str_starts_with($kind, 'payment') ? 'invoice_due' : 'system',
                'is_read' => false,
                'data'    => array_filter($data + ['kind' => $kind], fn ($v) => $v !== null),
            ]);
        } catch (\Throwable $e) {
            Log::error("Portal notification failed ({$kind}, user {$user->id}): " . $e->getMessage());
        }
    }

    private static function mail(string $email, ?string $name, string $title, string $body, array $mail): void
    {
        try {
            Mail::to($email)->send(new FitAccessMail(
                emailSubject:  $mail['subject'] ?? $title,
                heading:       $mail['heading'] ?? $title,
                recipientName: $name,
                paragraphs:    $mail['paragraphs'] ?? [$body],
                highlight:     $mail['highlight'] ?? null,
                details:       $mail['details'] ?? [],
                steps:         $mail['steps'] ?? [],
                buttonText:    $mail['buttonText'] ?? 'Open FitAccess',
                buttonUrl:     $mail['buttonUrl'] ?? config('app.frontend_url') . '/login',
                closing:       $mail['closing'] ?? null,
                files:         $mail['files'] ?? [],
                appLinks:      $mail['appLinks'] ?? [],
                appText:       $mail['appText'] ?? null,
            ));
        } catch (\Throwable $e) {
            Log::error("Notification email failed ({$email}): " . $e->getMessage());
        }
    }
}
