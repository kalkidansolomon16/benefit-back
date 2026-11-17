<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Support\CardNumber;
use Illuminate\Support\Collection;

/**
 * FitAccess sends each employee their own card number by e-mail (and Telegram
 * when linked). The company gets a short notice listing who received a card;
 * it does not need to forward anything. Card numbers never change.
 */
class CardIssuer
{
    /**
     * @param  Collection<int, \App\Models\Employee>  $employees
     * @return array{issued: int, skipped: string[]}|string  error text when nothing could be sent
     */
    public static function issue(Company $company, Collection $employees, bool $afterPayment = false)
    {
        $ok = collect();
        $skipped = [];
        foreach ($employees as $e) {
            $e->loadMissing('user');
            $why = match (true) {
                !$e->card_number => 'has no card number',
                ($e->registration_status ?? 'approved') !== 'approved' => 'is not approved by the company',
                $e->isAwaitingAdminApproval() || !$e->user?->is_active => 'has no final FitAccess approval',
                $e->is_banned => 'is banned',
                default => null,
            };
            $why ? $skipped[] = ($e->user?->name ?? "#{$e->id}") . " {$why}" : $ok->push($e);
        }

        if ($ok->isEmpty()) {
            return $skipped ? 'No card could be sent: ' . implode('; ', $skipped) . '.' : 'No cards to send.';
        }

        $delivered = collect();
        foreach ($ok as $e) {
            if (CardDelivery::toEmployee($e)) {
                $delivered->push($e);
                if (!$e->card_issued_at) {
                    $e->update(['card_issued_at' => now()]);
                    AuditLog::record('updated', $e, ['card_issued_at' => null], ['card_issued_at' => $e->card_issued_at]);
                }
            } else {
                $skipped[] = ($e->user?->name ?? "#{$e->id}") . ' could not be e-mailed';
            }
        }

        if ($delivered->isEmpty()) {
            return 'The card e-mails could not be sent. Please try again later.';
        }

        // For the company's records: who received their card number
        $n = $delivered->count();
        $lines = $delivered->map(fn ($e) => ($e->user?->name ?? 'Employee') . ($e->user?->email ? " ({$e->user->email})" : '')
            . ': ' . CardNumber::format($e->card_number))->values()->all();
        Notifier::company($company, 'cards_issued', "{$n} employee" . ($n === 1 ? '' : 's') . ' received their FitAccess card number',
            ($afterPayment ? 'Your payment is confirmed. ' : '')
            . "FitAccess e-mailed {$n} employee" . ($n === 1 ? '' : 's') . ' their personal card number. They activate it by entering it in the FitAccess portal; you do not need to do anything.',
            [
                'subject'    => "FitAccess card numbers sent to {$n} employee" . ($n === 1 ? '' : 's'),
                'heading'    => 'Card numbers sent to your employees',
                'paragraphs' => array_merge(
                    [($afterPayment ? 'Thank you, your payment is confirmed. ' : '') . 'FitAccess has e-mailed these employees their personal card number:'],
                    $lines,
                    ['Each employee activates their card by entering the number in the FitAccess portal. You can follow who has activated their card on the Employees page.'],
                ),
                'buttonText' => 'Open employees',
                'buttonUrl'  => config('app.frontend_url') . '/hr/employees',
            ]
        );

        return ['issued' => $n, 'skipped' => $skipped];
    }
}
