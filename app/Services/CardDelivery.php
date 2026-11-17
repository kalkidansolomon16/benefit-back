<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Notification;
use App\Support\CardNumber;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails (and Telegrams, when linked) an employee their own FitAccess card
 * number. FitAccess sends it straight to the employee; the company does not
 * have to pass it on.
 */
class CardDelivery
{
    /** @return string[] channels it went out by (empty = failed) */
    public static function toEmployee(Employee $employee): array
    {
        $employee->loadMissing('user', 'company');
        $user = $employee->user;
        if (!$user || !$employee->card_number) {
            return [];
        }

        $number  = CardNumber::format($employee->card_number);
        $company = $employee->company?->name ?? 'your company';
        $sent    = [];

        try {
            Mail::to($user->email)->send(new \App\Mail\FitAccessMail(
                emailSubject:  'Your FitAccess card number',
                heading:       'Your FitAccess card is ready',
                recipientName: $user->name,
                highlight:     "Card number: {$number}",
                paragraphs:    [
                    "Your FitAccess membership through {$company} is ready. Here is your personal card number.",
                    'Sign in to FitAccess, open Cards and enter this number once to activate your card. After that you can check in at partner gyms.',
                ],
                steps:         $employee->account_created_at ? [] : ['Create your account first from the FitAccess invitation e-mail (or ask your HR team to resend it).'],
                buttonText:    'Activate my card',
                buttonUrl:     config('app.frontend_url') . '/employee/cards',
                closing:       'Keep this number private: it is linked to your account and may not be shared.',
                appLinks:      \App\Support\Settings::appLinks(),
                appText:       'You can also activate your card in the FitAccess mobile app: sign in and enter this number under Cards.',
            ));
            $sent[] = 'email';
        } catch (\Throwable $e) {
            Log::error('Card email failed: ' . $e->getMessage());
        }

        if ($user->telegram_chat_id) {
            try {
                app(TelegramService::class)->sendMessage(
                    $user->telegram_chat_id,
                    "<b>Your FitAccess card</b>\n\nCard number: <code>{$number}</code>\n\nSign in to FitAccess and enter it once to activate your card."
                );
                $sent[] = 'telegram';
            } catch (\Throwable $e) {
                Log::error('Card Telegram message failed: ' . $e->getMessage());
            }
        }

        if ($sent) {
            // In-portal notice (the number itself only goes by email/Telegram)
            Notification::create([
                'user_id' => $user->id, 'type' => 'system', 'is_read' => false,
                'title'   => 'Your FitAccess card number has arrived',
                'body'    => 'FitAccess sent your card number to ' . $user->email . '. Enter it on the Cards page to activate your card.',
                'data'    => ['kind' => 'card_sent', 'link' => '/employee/cards'],
            ]);
            AuditLog::record('updated', $employee, [], ['card_sent_via' => implode(',', $sent)]);
        }

        return $sent;
    }
}
