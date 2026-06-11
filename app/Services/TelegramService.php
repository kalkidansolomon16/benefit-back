<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PartnerApplication;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private string $token;
    private string $apiBase;

    public function __construct()
    {
        $this->token   = config('services.telegram.bot_token', '');
        $this->apiBase = "https://api.telegram.org/bot{$this->token}";
    }

    /* ── Core HTTP helpers ─────────────────────────────────────────── */

    public function sendMessage(int|string $chatId, string $text, ?array $keyboard = null, string $parseMode = 'HTML'): ?array
    {
        if (!$this->token) return null;

        $payload = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => $parseMode,
        ];

        if ($keyboard) {
            $payload['reply_markup'] = json_encode($keyboard);
        }

        try {
            $response = Http::timeout(5)->post("{$this->apiBase}/sendMessage", $payload);
            return $response->json();
        } catch (\Throwable $e) {
            Log::warning("Telegram sendMessage failed: {$e->getMessage()}");
            return null;
        }
    }

    public function editMessageText(int|string $chatId, int $messageId, string $text, ?array $keyboard = null): void
    {
        if (!$this->token) return;

        $payload = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];
        if ($keyboard) {
            $payload['reply_markup'] = json_encode($keyboard);
        }

        try {
            Http::timeout(5)->post("{$this->apiBase}/editMessageText", $payload);
        } catch (\Throwable $e) {
            Log::warning("Telegram editMessageText failed: {$e->getMessage()}");
        }
    }

    public function answerCallbackQuery(string $callbackId, string $text = '', bool $showAlert = false): void
    {
        if (!$this->token) return;
        try {
            Http::timeout(5)->post("{$this->apiBase}/answerCallbackQuery", [
                'callback_query_id' => $callbackId,
                'text'              => $text,
                'show_alert'        => $showAlert,
            ]);
        } catch (\Throwable $e) {
            Log::warning("Telegram answerCallbackQuery failed: {$e->getMessage()}");
        }
    }

    /* ── Inline keyboards ──────────────────────────────────────────── */

    public function companyApprovalKeyboard(int $companyId): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '✅ Approve',  'callback_data' => "approve_company_{$companyId}"],
                ['text' => '❌ Reject',   'callback_data' => "reject_company_{$companyId}"],
            ]],
        ];
    }

    public function employeeApprovalKeyboard(int $employeeId): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '✅ Approve',  'callback_data' => "approve_emp_{$employeeId}"],
                ['text' => '❌ Reject',   'callback_data' => "reject_emp_{$employeeId}"],
            ]],
        ];
    }

    public function gymApprovalKeyboard(int $applicationId): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '✅ Approve',  'callback_data' => "approve_gym_{$applicationId}"],
                ['text' => '❌ Reject',   'callback_data' => "reject_gym_{$applicationId}"],
            ]],
        ];
    }

    /* ── New registration alerts → all linked admins ───────────────── */

    public function notifyAdminsNewCompany(Company $company, User $hrUser): void
    {
        $admins = User::whereIn('role', ['super_admin', 'fitaccess_admin'])
            ->whereNotNull('telegram_chat_id')
            ->get();

        $text = "🏢 <b>New Company Registration</b>\n\n"
              . "<b>Company:</b> {$company->name}\n"
              . "<b>Contact:</b> {$hrUser->name}\n"
              . "<b>Email:</b> {$hrUser->email}\n"
              . "<b>Industry:</b> {$company->industry}\n\n"
              . "⏳ Waiting for licence review.";

        foreach ($admins as $admin) {
            $this->sendMessage(
                $admin->telegram_chat_id,
                $text,
                $this->companyApprovalKeyboard($company->id)
            );
        }
    }

    public function notifyAdminsNewPartner(PartnerApplication $app): void
    {
        $admins = User::whereIn('role', ['super_admin', 'fitaccess_admin'])
            ->whereNotNull('telegram_chat_id')
            ->get();

        $text = "🏋️ <b>New Gym Partner Application</b>\n\n"
              . "<b>Facility:</b> {$app->facility_name}\n"
              . "<b>Contact:</b> {$app->contact_person}\n"
              . "<b>Email:</b> {$app->contact_email}\n"
              . "<b>City:</b> {$app->city}\n\n"
              . "⏳ Waiting for admin review.";

        foreach ($admins as $admin) {
            $this->sendMessage(
                $admin->telegram_chat_id,
                $text,
                $this->gymApprovalKeyboard($app->id)
            );
        }
    }

    public function notifyHRNewEmployee(Employee $employee): void
    {
        // Notify the company's HR users who have Telegram linked
        $hrUsers = User::where('company_id', $employee->company_id)
            ->whereIn('role', ['company_hr', 'company_finance', 'company_ceo'])
            ->whereNotNull('telegram_chat_id')
            ->get();

        // Also notify admins
        $adminUsers = User::whereIn('role', ['super_admin', 'fitaccess_admin'])
            ->whereNotNull('telegram_chat_id')
            ->get();

        $name   = $employee->user?->name ?? 'Unknown';
        $company = $employee->company?->name ?? 'Unknown';

        $hrText = "👤 <b>New Employee Registration</b>\n\n"
                . "<b>Name:</b> {$name}\n"
                . "<b>Department:</b> " . ($employee->department ?? 'N/A') . "\n"
                . "<b>Staff ID:</b> {$employee->fan_number}\n\n"
                . "⏳ Waiting for your HR review.";

        foreach ($hrUsers as $hrUser) {
            $this->sendMessage(
                $hrUser->telegram_chat_id,
                $hrText,
                $this->employeeApprovalKeyboard($employee->id)
            );
        }

        $adminText = "👤 <b>New Employee Registration</b>\n\n"
                   . "<b>Name:</b> {$name}\n"
                   . "<b>Company:</b> {$company}\n"
                   . "<b>Staff ID:</b> {$employee->fan_number}\n\n"
                   . "⏳ Pending HR review first.";

        foreach ($adminUsers as $admin) {
            $this->sendMessage($admin->telegram_chat_id, $adminText);
        }
    }

    /* ── Status notifications → individual users ───────────────────── */

    public function notifyUserApproved(User $user, string $entityType): void
    {
        if (!$user->telegram_chat_id) return;

        $messages = [
            'company'  => "✅ <b>Your company account has been approved!</b>\n\nYou can now log in to the FitAccess HR portal and start managing your team.",
            'employee' => "✅ <b>Your account has been approved!</b>\n\nYou can now log in to the FitAccess employee portal and access your membership benefits.",
            'partner'  => "✅ <b>Your gym partner application has been approved!</b>\n\nYour facility is now live on FitAccess. Log in to the partner portal to manage your profile.",
            'hr_approved' => "✅ <b>Your account has been approved by HR!</b>\n\nFinal admin review is in progress. You'll be notified when fully activated.",
        ];

        $this->sendMessage($user->telegram_chat_id, $messages[$entityType] ?? "✅ Your account has been approved!");
    }

    public function notifyUserRejected(User $user, string $entityType, ?string $reason = null): void
    {
        if (!$user->telegram_chat_id) return;

        $messages = [
            'company'  => "❌ <b>Company registration rejected.</b>\n\nYour company licence application was not approved.",
            'employee' => "❌ <b>Your registration was rejected.</b>",
            'partner'  => "❌ <b>Gym partner application rejected.</b>",
        ];

        $text = $messages[$entityType] ?? "❌ Your application was rejected.";

        if ($reason) {
            $text .= "\n\n<b>Reason:</b> " . htmlspecialchars($reason);
        }

        $text .= "\n\nPlease contact FitAccess support for more information.";

        $this->sendMessage($user->telegram_chat_id, $text);
    }

    public function notifyUserSuspended(User $user, string $reason = ''): void
    {
        if (!$user->telegram_chat_id) return;

        $text = "⚠️ <b>Your FitAccess account has been suspended.</b>";
        if ($reason) {
            $text .= "\n\n<b>Reason:</b> " . htmlspecialchars($reason);
        }
        $text .= "\n\nContact support to resolve this issue.";

        $this->sendMessage($user->telegram_chat_id, $text);
    }
}
