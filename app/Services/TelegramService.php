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

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $http = Http::timeout(15);

        // Only skip SSL verification and use proxy in local development
        if (app()->environment('local')) {
            $http = $http->withoutVerifying();
            $proxy = config('services.telegram.proxy');
            if ($proxy) {
                $http = $http->withOptions(['proxy' => $proxy]);
            }
        }

        return $http;
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
            $response = $this->http()->post("{$this->apiBase}/sendMessage", $payload);
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
            $this->http()->post("{$this->apiBase}/editMessageText", $payload);
        } catch (\Throwable $e) {
            Log::warning("Telegram editMessageText failed: {$e->getMessage()}");
        }
    }

    public function answerCallbackQuery(string $callbackId, string $text = '', bool $showAlert = false): void
    {
        if (!$this->token) return;
        try {
            $this->http()->post("{$this->apiBase}/answerCallbackQuery", [
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

    /* ── New registration alerts ───────────────────────────────────── */

    public function notifyAdminsNewCompany(Company $company, User $hrUser): void
    {
        $admins = User::whereIn('role', ['super_admin', 'fitaccess_admin'])
            ->whereNotNull('telegram_chat_id')->get();

        $text = "🏢 <b>New Company Registration</b>\n\n"
              . "<b>Company:</b> {$company->name}\n"
              . "<b>Contact:</b> {$hrUser->name}\n"
              . "<b>Email:</b> {$hrUser->email}\n"
              . "<b>Industry:</b> {$company->industry}\n\n"
              . "⏳ Waiting for licence review.";

        foreach ($admins as $admin) {
            $this->sendMessage($admin->telegram_chat_id, $text, $this->companyApprovalKeyboard($company->id));
        }

        // Also notify the HR user that registration was received
        if ($hrUser->telegram_chat_id) {
            $this->sendMessage($hrUser->telegram_chat_id,
                "📨 <b>Registration Received</b>\n\nYour company <b>{$company->name}</b> has been submitted for review.\n\nOur team will verify your business licence and notify you within 2–3 business days."
            );
        }
    }

    public function notifyAdminsNewPartner(PartnerApplication $app): void
    {
        $admins = User::whereIn('role', ['super_admin', 'fitaccess_admin'])
            ->whereNotNull('telegram_chat_id')->get();

        $text = "🏋️ <b>New Gym Partner Application</b>\n\n"
              . "<b>Facility:</b> {$app->facility_name}\n"
              . "<b>Contact:</b> {$app->contact_person}\n"
              . "<b>Email:</b> {$app->contact_email}\n"
              . "<b>City:</b> {$app->city}\n\n"
              . "⏳ Waiting for admin review.";

        foreach ($admins as $admin) {
            $this->sendMessage($admin->telegram_chat_id, $text, $this->gymApprovalKeyboard($app->id));
        }

        // Also notify the partner that their application was received
        if ($app->user?->telegram_chat_id) {
            $this->sendMessage($app->user->telegram_chat_id,
                "📨 <b>Application Received</b>\n\nYour gym partner application for <b>{$app->facility_name}</b> has been submitted.\n\nOur team will review it within 2–3 business days and notify you."
            );
        }
    }

    /**
     * Find the primary HR user for a company via email match (how hrUser() relationship works).
     * The company_hr user is NOT stored with company_id on the users table — only linked by email.
     */
    private function getCompanyHRUsers(int $companyId): \Illuminate\Database\Eloquent\Collection
    {
        $company = \App\Models\Company::find($companyId);
        if (!$company) return collect();

        // Primary HR user: matched by email = company.contact_email
        $primaryHR = User::where('email', $company->contact_email)
            ->whereNotNull('telegram_chat_id')
            ->get();

        // Sub-roles who DO have company_id set (team members added later)
        $subRoles = User::where('company_id', $companyId)
            ->whereIn('role', ['co_hr', 'co_executive', 'co_finance', 'company_finance', 'company_ceo'])
            ->whereNotNull('telegram_chat_id')
            ->get();

        return $primaryHR->merge($subRoles)->unique('id');
    }

    public function notifyHRNewEmployee(Employee $employee): void
    {
        $hrUsers    = $this->getCompanyHRUsers($employee->company_id);
        $adminUsers = User::whereIn('role', ['super_admin', 'fitaccess_admin'])
            ->whereNotNull('telegram_chat_id')->get();

        $name        = $employee->user?->name ?? 'Unknown';
        $companyName = $employee->company?->name ?? 'Unknown';

        $hrText = "👤 <b>New Employee Registration</b>\n\n"
                . "<b>Name:</b> {$name}\n"
                . "<b>Department:</b> " . ($employee->department ?? 'N/A') . "\n"
                . "<b>Staff ID:</b> {$employee->fan_number}\n\n"
                . "⏳ Waiting for your HR review.";

        foreach ($hrUsers as $hrUser) {
            $this->sendMessage($hrUser->telegram_chat_id, $hrText, $this->employeeApprovalKeyboard($employee->id));
        }

        $adminText = "👤 <b>New Employee Registration</b>\n\n"
                   . "<b>Name:</b> {$name}\n"
                   . "<b>Company:</b> {$companyName}\n"
                   . "<b>Staff ID:</b> {$employee->fan_number}\n\n"
                   . "⏳ Pending HR review first.";

        foreach ($adminUsers as $admin) {
            $this->sendMessage($admin->telegram_chat_id, $adminText);
        }

        // Also notify the employee themselves
        if ($employee->user?->telegram_chat_id) {
            $this->sendMessage($employee->user->telegram_chat_id,
                "📨 <b>Registration Received</b>\n\nYour employee registration has been submitted to <b>{$companyName}</b>.\n\nYour HR team will review your application and notify you."
            );
        }
    }

    /* ── Notify company HR when their employee is fully approved ───── */

    public function notifyHREmployeeApprovedByAdmin(Employee $employee): void
    {
        $hrUsers = $this->getCompanyHRUsers($employee->company_id);
        $name    = $employee->user?->name ?? 'Unknown';

        foreach ($hrUsers as $hr) {
            $this->sendMessage($hr->telegram_chat_id,
                "✅ <b>Employee Fully Activated</b>\n\n<b>{$name}</b> has been approved by admin and their FitAccess account is now active."
            );
        }
    }

    /* ── Notify company HR when their employee is rejected by admin ── */

    public function notifyHREmployeeRejectedByAdmin(Employee $employee, ?string $reason = null): void
    {
        $hrUsers = $this->getCompanyHRUsers($employee->company_id);
        $name    = $employee->user?->name ?? 'Unknown';
        $text    = "❌ <b>Employee Rejected by Admin</b>\n\n<b>{$name}</b>'s account was rejected at the admin review stage.";
        if ($reason) $text .= "\n\n<b>Reason:</b> " . htmlspecialchars($reason);

        foreach ($hrUsers as $hr) {
            $this->sendMessage($hr->telegram_chat_id, $text);
        }
    }

    /* ── Account status change (activate/deactivate) ───────────────── */

    public function notifyUserActivated(User $user): void
    {
        if (!$user->telegram_chat_id) return;
        $this->sendMessage($user->telegram_chat_id,
            "✅ <b>Account Activated</b>\n\nYour FitAccess account has been activated. You can now log in."
        );
    }

    public function notifyUserDeactivated(User $user): void
    {
        if (!$user->telegram_chat_id) return;
        $this->sendMessage($user->telegram_chat_id,
            "⚠️ <b>Account Deactivated</b>\n\nYour FitAccess account has been deactivated. Contact support for assistance."
        );
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

    /* ── Billing notifications ─────────────────────────────────────── */

    public function notifyCompanyInvoiceSent(\App\Models\BillingInvoice $invoice): void
    {
        $hrUsers = $this->getCompanyHRUsers($invoice->company_id);
        if ($hrUsers->isEmpty()) return;

        $dueDate = $invoice->due_date
            ? \Carbon\Carbon::parse($invoice->due_date)->format('M d, Y')
            : 'N/A';
        $total = number_format((float) $invoice->total_amount, 0);

        $text = "🧾 <b>New Invoice Ready</b>\n\n"
              . "<b>Invoice #:</b> {$invoice->invoice_number}\n"
              . "<b>Period:</b> {$invoice->billing_period}\n"
              . "<b>Total:</b> ETB {$total}\n"
              . "<b>Due:</b> {$dueDate}\n\n"
              . "Please log in to the HR portal to view and submit your payment receipt.";

        foreach ($hrUsers as $hr) {
            $this->sendMessage($hr->telegram_chat_id, $text);
        }
    }

    public function notifyCompanyPaymentVerified(\App\Models\BillingPayment $payment): void
    {
        $hrUsers = $this->getCompanyHRUsers($payment->company_id);
        if ($hrUsers->isEmpty()) return;

        $invoice = $payment->invoice;
        $total   = number_format((float) $payment->amount, 0);

        $text = "✅ <b>Payment Confirmed</b>\n\n"
              . "<b>Invoice #:</b> " . ($invoice?->invoice_number ?? 'N/A') . "\n"
              . "<b>Period:</b> " . ($invoice?->billing_period ?? 'N/A') . "\n"
              . "<b>Amount:</b> ETB {$total}\n\n"
              . "All enrolled employees' gym memberships are now active. 🏋️";

        foreach ($hrUsers as $hr) {
            $this->sendMessage($hr->telegram_chat_id, $text);
        }
    }

    public function notifyCompanyPaymentRejected(\App\Models\BillingPayment $payment, ?string $reason = null): void
    {
        $hrUsers = $this->getCompanyHRUsers($payment->company_id);
        if ($hrUsers->isEmpty()) return;

        $invoice = $payment->invoice;
        $total   = number_format((float) $payment->amount, 0);

        $text = "❌ <b>Payment Receipt Rejected</b>\n\n"
              . "<b>Invoice #:</b> " . ($invoice?->invoice_number ?? 'N/A') . "\n"
              . "<b>Amount:</b> ETB {$total}\n"
              . ($reason ? "\n<b>Reason:</b> " . htmlspecialchars($reason) : '')
              . "\n\nPlease log in to the HR portal and resubmit your payment receipt.";

        foreach ($hrUsers as $hr) {
            $this->sendMessage($hr->telegram_chat_id, $text);
        }
    }

    /* ── User status ───────────────────────────────────────────────── */

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

    public function notifyUserBanned(User $user, string $until, string $reason = ''): void
    {
        if (!$user->telegram_chat_id) return;

        $text = "🚫 <b>Your FitAccess gym access has been suspended.</b>"
            . "\n\n<b>Suspended until:</b> " . htmlspecialchars($until);

        if ($reason) {
            $text .= "\n<b>Reason:</b> " . htmlspecialchars($reason);
        }

        $text .= "\n\nYou will not be able to log in until the suspension period ends."
            . "\nContact your HR team if you believe this is a mistake.";

        $this->sendMessage($user->telegram_chat_id, $text);
    }
}
