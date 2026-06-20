<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\PartnerApplication;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TelegramController extends Controller
{
    public function __construct(private TelegramService $telegram) {}

    /**
     * Telegram sends all updates here (webhook).
     * This endpoint must be public (no auth middleware).
     */
    public function webhook(Request $request): JsonResponse
    {
        $update = $request->all();

        try {
            if (isset($update['message'])) {
                $this->handleMessage($update['message']);
            } elseif (isset($update['callback_query'])) {
                $this->handleCallbackQuery($update['callback_query']);
            }
        } catch (\Throwable $e) {
            Log::error('Telegram webhook error: ' . $e->getMessage(), ['update' => $update]);
        }

        // Always return 200 so Telegram doesn't retry
        return response()->json(['ok' => true]);
    }

    /* ── Message handler ───────────────────────────────────────────── */

    private function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $text   = trim($message['text'] ?? '');
        $from   = $message['from'] ?? [];

        if (str_starts_with($text, '/start')) {
            $this->handleStart($chatId, $from);
        } elseif (str_starts_with($text, '/link')) {
            $code = trim(substr($text, 5));
            $this->handleLink($chatId, $from, $code);
        } elseif (str_starts_with($text, '/status')) {
            $this->handleStatus($chatId);
        } elseif (str_starts_with($text, '/unlink')) {
            $this->handleUnlink($chatId);
        } elseif (str_starts_with($text, '/help')) {
            $this->handleHelp($chatId);
        } else {
            $this->telegram->sendMessage($chatId,
                "I didn't understand that. Send /help to see available commands."
            );
        }
    }

    private function handleStart(int|string $chatId, array $from): void
    {
        $name = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
        $this->telegram->sendMessage($chatId,
            "👋 <b>Welcome to FitAccess Bot</b>, {$name}!\n\n"
            . "To receive notifications, link your account:\n"
            . "1. Log in to the FitAccess portal\n"
            . "2. Go to <b>Settings → Telegram</b>\n"
            . "3. Generate a link code\n"
            . "4. Send <code>/link YOUR_CODE</code> here\n\n"
            . "<b>Available commands:</b>\n"
            . "/link CODE — Link your FitAccess account\n"
            . "/status — Check your membership status\n"
            . "/unlink — Disconnect your account\n"
            . "/help — Show this message"
        );
    }

    private function handleLink(int|string $chatId, array $from, string $code): void
    {
        if (!$code) {
            $this->telegram->sendMessage($chatId,
                "Please provide your link code. Example:\n<code>/link ABC12345</code>"
            );
            return;
        }

        $upperCode = strtoupper($code);
        $nowTime   = now();

        $dbRow = \DB::selectOne(
            'SELECT id, telegram_link_token, telegram_link_expires_at FROM users WHERE telegram_link_token = ?',
            [$upperCode]
        );

        Log::info('Telegram /link debug', [
            'received_code' => $code,
            'upper_code'    => $upperCode,
            'php_now'       => $nowTime->toDateTimeString(),
            'db_row'        => $dbRow ? (array) $dbRow : null,
        ]);

        $user = User::where('telegram_link_token', $upperCode)
            ->where('telegram_link_expires_at', '>', $nowTime)
            ->first();

        if (!$user) {
            $this->telegram->sendMessage($chatId,
                "❌ Invalid or expired code. Please generate a new one from your FitAccess portal settings."
            );
            return;
        }

        // Check the code isn't already used by someone else
        if (User::where('telegram_chat_id', $chatId)->where('id', '!=', $user->id)->exists()) {
            $this->telegram->sendMessage($chatId,
                "⚠️ This Telegram account is already linked to another FitAccess account. Please unlink it first with /unlink."
            );
            return;
        }

        $user->update([
            'telegram_chat_id'        => $chatId,
            'telegram_link_token'     => null,
            'telegram_link_expires_at'=> null,
        ]);

        $this->telegram->sendMessage($chatId,
            "✅ <b>Account linked successfully!</b>\n\n"
            . "You'll now receive notifications for approvals, rejections, and account updates."
        );
    }

    private function handleStatus(int|string $chatId): void
    {
        $user = User::where('telegram_chat_id', $chatId)->first();

        if (!$user) {
            $this->telegram->sendMessage($chatId,
                "Your Telegram is not linked to a FitAccess account yet.\nSend /link CODE to connect."
            );
            return;
        }

        if ($user->role !== 'employee') {
            $this->telegram->sendMessage($chatId,
                "👋 Hello, <b>{$user->name}</b>!\n\nYour account is linked and notifications are active."
            );
            return;
        }

        $employee = $user->employee;
        if (!$employee) {
            $this->telegram->sendMessage($chatId, "No employee profile found for your account.");
            return;
        }

        $membership = $employee->activeMembership;
        $status     = $membership?->status ?? ($employee->is_enrolled ? 'active' : 'pending');
        $planName   = $membership?->plan?->name ?? 'N/A';
        $validUntil = $membership?->end_date?->toDateString() ?? 'N/A';

        $statusEmoji = match($status) {
            'active'   => '🟢',
            'pending'  => '🟡',
            'suspended'=> '🔴',
            default    => '⚪',
        };

        $this->telegram->sendMessage($chatId,
            "👤 <b>{$user->name}</b>\n\n"
            . "{$statusEmoji} <b>Status:</b> " . ucfirst($status) . "\n"
            . "📦 <b>Plan:</b> {$planName}\n"
            . "📅 <b>Valid until:</b> {$validUntil}\n"
            . "🏢 <b>Company:</b> " . ($employee->company?->name ?? 'N/A')
        );
    }

    private function handleUnlink(int|string $chatId): void
    {
        $user = User::where('telegram_chat_id', $chatId)->first();

        if (!$user) {
            $this->telegram->sendMessage($chatId,
                "No FitAccess account is linked to this Telegram."
            );
            return;
        }

        $user->update(['telegram_chat_id' => null]);

        $this->telegram->sendMessage($chatId,
            "✅ Your FitAccess account has been unlinked. You will no longer receive notifications here."
        );
    }

    private function handleHelp(int|string $chatId): void
    {
        $this->telegram->sendMessage($chatId,
            "🤖 <b>FitAccess Bot Commands</b>\n\n"
            . "/start — Welcome message and setup guide\n"
            . "/link CODE — Link your FitAccess account\n"
            . "/status — Check your membership status\n"
            . "/unlink — Disconnect this Telegram from your account\n"
            . "/help — Show this help message\n\n"
            . "For support, contact your FitAccess administrator."
        );
    }

    /* ── Callback query handler (inline button clicks) ─────────────── */

    private function handleCallbackQuery(array $query): void
    {
        $callbackId = $query['id'];
        $chatId     = $query['message']['chat']['id'];
        $messageId  = $query['message']['message_id'];
        $data       = $query['data'] ?? '';

        // Verify the clicker is a linked admin/HR user
        $actor = User::where('telegram_chat_id', $chatId)->first();

        if (!$actor) {
            $this->telegram->answerCallbackQuery($callbackId, '⚠️ Link your account first.', true);
            return;
        }

        if (preg_match('/^(approve|reject)_(company|emp|gym)_(\d+)$/', $data, $m)) {
            [, $action, $type, $id] = $m;
            $this->dispatchApproval($actor, $action, $type, (int) $id, $callbackId, $chatId, $messageId);
        } else {
            $this->telegram->answerCallbackQuery($callbackId, 'Unknown action.');
        }
    }

    private function dispatchApproval(User $actor, string $action, string $type, int $id, string $callbackId, int|string $chatId, int $messageId): void
    {
        $isAdmin = in_array($actor->role, ['super_admin', 'fitaccess_admin']);
        $isHR    = in_array($actor->role, ['company_hr', 'company_finance', 'company_ceo']);

        try {
            switch ("{$action}_{$type}") {

                case 'approve_company':
                    if (!$isAdmin) {
                        $this->telegram->answerCallbackQuery($callbackId, '⛔ Admin access required.', true);
                        return;
                    }
                    $company = Company::findOrFail($id);
                    if ($company->business_license_status !== 'pending') {
                        $this->telegram->answerCallbackQuery($callbackId, 'Already processed.', true);
                        return;
                    }
                    $company->update(['business_license_status' => 'approved', 'is_active' => true]);
                    $company->hrUser?->update(['is_active' => true]);
                    $this->telegram->notifyUserApproved($company->hrUser, 'company');
                    $this->telegram->answerCallbackQuery($callbackId, '✅ Company approved!');
                    $this->telegram->editMessageText($chatId, $messageId,
                        "✅ <b>Company approved</b> by {$actor->name}.\n<b>{$company->name}</b> is now active."
                    );
                    break;

                case 'reject_company':
                    if (!$isAdmin) {
                        $this->telegram->answerCallbackQuery($callbackId, '⛔ Admin access required.', true);
                        return;
                    }
                    $company = Company::findOrFail($id);
                    if ($company->business_license_status !== 'pending') {
                        $this->telegram->answerCallbackQuery($callbackId, 'Already processed.', true);
                        return;
                    }
                    $company->update(['business_license_status' => 'rejected']);
                    $this->telegram->notifyUserRejected($company->hrUser, 'company');
                    $this->telegram->answerCallbackQuery($callbackId, '❌ Company rejected.');
                    $this->telegram->editMessageText($chatId, $messageId,
                        "❌ <b>Company rejected</b> by {$actor->name}.\n<b>{$company->name}</b>"
                    );
                    break;

                case 'approve_emp':
                    if (!$isAdmin && !$isHR) {
                        $this->telegram->answerCallbackQuery($callbackId, '⛔ Insufficient permissions.', true);
                        return;
                    }
                    $employee = Employee::with('user')->findOrFail($id);
                    if ($isHR && $employee->registration_status !== 'pending') {
                        $this->telegram->answerCallbackQuery($callbackId, 'Already processed.', true);
                        return;
                    }
                    if ($isHR) {
                        $employee->update(['registration_status' => 'approved', 'admin_approval_status' => 'pending']);
                        $this->telegram->notifyUserApproved($employee->user, 'hr_approved');
                    } else {
                        $employee->update(['admin_approval_status' => 'approved', 'is_enrolled' => true]);
                        $employee->user?->update(['is_active' => true]);
                        $this->telegram->notifyUserApproved($employee->user, 'employee');
                    }
                    $this->telegram->answerCallbackQuery($callbackId, '✅ Employee approved!');
                    $this->telegram->editMessageText($chatId, $messageId,
                        "✅ <b>Employee approved</b> by {$actor->name}.\n<b>{$employee->user?->name}</b>"
                    );
                    break;

                case 'reject_emp':
                    if (!$isAdmin && !$isHR) {
                        $this->telegram->answerCallbackQuery($callbackId, '⛔ Insufficient permissions.', true);
                        return;
                    }
                    $employee = Employee::with('user')->findOrFail($id);
                    $employee->update(['registration_status' => 'rejected']);
                    $employee->user?->update(['is_active' => false]);
                    $this->telegram->notifyUserRejected($employee->user, 'employee');
                    $this->telegram->answerCallbackQuery($callbackId, '❌ Employee rejected.');
                    $this->telegram->editMessageText($chatId, $messageId,
                        "❌ <b>Employee rejected</b> by {$actor->name}.\n<b>{$employee->user?->name}</b>"
                    );
                    break;

                case 'approve_gym':
                    if (!$isAdmin) {
                        $this->telegram->answerCallbackQuery($callbackId, '⛔ Admin access required.', true);
                        return;
                    }
                    $app = PartnerApplication::with('user')->findOrFail($id);
                    if ($app->status !== 'pending') {
                        $this->telegram->answerCallbackQuery($callbackId, 'Already processed.', true);
                        return;
                    }
                    // Note: full gym creation requires a tier — quick-approve sets 'basic' as default
                    DB::transaction(function () use ($app) {
                        \App\Models\Gym::create([
                            'name'              => $app->facility_name,
                            'contact_person'    => $app->contact_person,
                            'contact_phone'     => $app->contact_phone,
                            'contact_email'     => $app->contact_email,
                            'address'           => $app->woreda,
                            'city'              => $app->city,
                            'tier'              => 'basic',
                            'max_capacity'      => $app->max_capacity,
                            'facilities'        => array_merge($app->categories ?? [], $app->amenities ?? []),
                            'opening_hours'     => [],
                            'is_active'         => true,
                            'is_partner'        => true,
                            'partnership_start' => now()->toDateString(),
                        ]);
                        $app->update(['status' => 'approved']);
                        $app->user?->update(['is_active' => true]);
                    });
                    $this->telegram->notifyUserApproved($app->user, 'partner');
                    $this->telegram->answerCallbackQuery($callbackId, '✅ Gym approved (tier: basic)!');
                    $this->telegram->editMessageText($chatId, $messageId,
                        "✅ <b>Gym approved</b> by {$actor->name}.\n<b>{$app->facility_name}</b> (tier: basic)\n\n"
                        . "⚠️ Visit the admin portal to update the tier if needed."
                    );
                    break;

                case 'reject_gym':
                    if (!$isAdmin) {
                        $this->telegram->answerCallbackQuery($callbackId, '⛔ Admin access required.', true);
                        return;
                    }
                    $app = PartnerApplication::with('user')->findOrFail($id);
                    if ($app->status !== 'pending') {
                        $this->telegram->answerCallbackQuery($callbackId, 'Already processed.', true);
                        return;
                    }
                    $app->update(['status' => 'rejected']);
                    $app->user?->update(['is_active' => false]);
                    $this->telegram->notifyUserRejected($app->user, 'partner');
                    $this->telegram->answerCallbackQuery($callbackId, '❌ Gym rejected.');
                    $this->telegram->editMessageText($chatId, $messageId,
                        "❌ <b>Gym rejected</b> by {$actor->name}.\n<b>{$app->facility_name}</b>"
                    );
                    break;

                default:
                    $this->telegram->answerCallbackQuery($callbackId, 'Unknown action.');
            }
        } catch (\Throwable $e) {
            Log::error('Telegram approval error: ' . $e->getMessage());
            $this->telegram->answerCallbackQuery($callbackId, '⚠️ An error occurred. Please use the portal.', true);
        }
    }
}
