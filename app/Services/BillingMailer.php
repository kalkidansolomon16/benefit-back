<?php

namespace App\Services;

use App\Mail\FitAccessMail;
use App\Models\BillingInvoice;
use App\Models\Company;
use App\Models\User;
use App\Support\BillingPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Billing emails to companies: a new invoice (with the period it pays for),
 * payment confirmation (with the new "covered until" date) and the renewal
 * reminder before a paid period ends. A mail failure is logged and never
 * breaks the action that triggered it.
 */
class BillingMailer
{
    /**
     * The company's billing contacts: its contact email, its HR / finance
     * accounts and, for a branch invoice, that branch's own login.
     *
     * @return array<string, string>  email => name
     */
    public static function companyRecipients(Company $company, ?int $branchId = null): array
    {
        $to = [];
        if ($company->contact_email) {
            $to[strtolower($company->contact_email)] = $company->contact_person ?: $company->name;
        }

        User::where('company_id', $company->id)
            ->where('is_active', true)
            ->whereIn('role', ['company_hr', 'co_hr', 'co_finance', 'company_finance'])
            ->where(fn($q) => $branchId
                ? $q->whereNull('company_branch_id')->orWhere('company_branch_id', $branchId)
                : $q->whereNull('company_branch_id'))
            ->whereNotNull('email')
            ->get(['name', 'email'])
            ->each(function (User $u) use (&$to) {
                $to[strtolower($u->email)] ??= $u->name;
            });

        return $to;
    }

    private static function send(array $recipients, callable $build, string $context): array
    {
        $sent = [];
        foreach ($recipients as $email => $name) {
            try {
                Mail::to($email)->send($build($name));
                $sent[] = $email;
            } catch (\Throwable $e) {
                Log::error("Billing email failed ({$context}, {$email}): " . $e->getMessage());
            }
        }

        return $sent;
    }

    private static function coverageText(BillingInvoice $inv): string
    {
        if ($inv->period_start && $inv->period_end) {
            return BillingPeriod::rangeText($inv->period_start, $inv->period_end)
                . ' (' . (int) $inv->period_months . ' month' . ((int) $inv->period_months === 1 ? '' : 's') . ')';
        }

        return (string) $inv->billing_period;
    }

    /** @return string[] addresses the invoice was sent to */
    public static function invoiceSent(BillingInvoice $inv): array
    {
        $company = $inv->company;
        if (!$company) {
            return [];
        }

        $employees = (int) $inv->items->sum('employee_count');
        $details = array_filter([
            'Invoice number'  => $inv->invoice_number,
            'Branch'          => $inv->branch_scoped ? ($inv->companyBranch?->name ?? 'Head office') : null,
            'Coverage period' => self::coverageText($inv),
            'Employees'       => (string) $employees,
            'Total'           => 'ETB ' . number_format((float) $inv->total_amount, 2),
            'Due date'        => $inv->due_date?->format('j M Y'),
        ]);

        $until = $inv->period_end?->format('j M Y');

        return self::send(
            self::companyRecipients($company, $inv->branch_scoped ? $inv->company_branch_id : null),
            fn($name) => new FitAccessMail(
                emailSubject: "FitAccess invoice {$inv->invoice_number} — " . self::coverageText($inv),
                heading: 'Your FitAccess invoice is ready',
                recipientName: $name,
                highlight: 'Total: ETB ' . number_format((float) $inv->total_amount, 2),
                paragraphs: [
                    "A new invoice for {$company->name} is waiting for payment in the FitAccess HR portal.",
                    $until
                        ? "Once it is paid, your {$employees} employee" . ($employees === 1 ? '' : 's') . " can use FitAccess partner facilities until {$until}. If it is not paid, their access stops when their current period ends."
                        : 'Once it is paid, your employees keep their FitAccess access.',
                ],
                details: $details,
                buttonText: 'View and pay invoice',
                buttonUrl: config('app.frontend_url') . '/hr/billing',
                closing: 'You can choose how often you are invoiced (every 1, 3, 6 or 12 months) on the Billing page of the HR portal.',
            ),
            "invoice {$inv->invoice_number}"
        );
    }

    public static function paymentConfirmed(BillingInvoice $inv, int $employees): void
    {
        $company = $inv->company;
        if (!$company) {
            return;
        }
        $until = $inv->period_end?->format('j M Y');

        self::send(
            self::companyRecipients($company, $inv->branch_scoped ? $inv->company_branch_id : null),
            fn($name) => new FitAccessMail(
                emailSubject: "Payment received — FitAccess invoice {$inv->invoice_number}",
                heading: 'Thank you, your payment is confirmed',
                recipientName: $name,
                highlight: $until ? "Your employees are covered until {$until}" : null,
                paragraphs: [
                    "We have verified the payment for invoice {$inv->invoice_number}.",
                    "{$employees} employee" . ($employees === 1 ? ' now has' : 's now have') . ' access to FitAccess partner facilities'
                        . ($until ? " until {$until}" : '') . '.',
                ],
                details: array_filter([
                    'Invoice number'  => $inv->invoice_number,
                    'Coverage period' => self::coverageText($inv),
                    'Amount'          => 'ETB ' . number_format((float) $inv->total_amount, 2),
                ]),
                buttonText: 'Open the HR portal',
                buttonUrl: config('app.frontend_url') . '/hr/billing',
            ),
            "payment {$inv->invoice_number}"
        );
    }

    /**
     * Reminder that some employees' paid period ends soon.
     *
     * @param  array<int, array{company: Company, until: Carbon, count: int}>  $rows
     */
    public static function renewalReminders(array $rows): int
    {
        $sent = 0;
        foreach ($rows as $row) {
            $company = $row['company'];
            $until   = $row['until']->format('j M Y');
            $count   = $row['count'];
            $sent += count(self::send(
                self::companyRecipients($company),
                fn($name) => new FitAccessMail(
                    emailSubject: "FitAccess access for {$count} employee" . ($count === 1 ? '' : 's') . " ends on {$until}",
                    heading: 'Time to renew FitAccess',
                    recipientName: $name,
                    highlight: "Paid period ends on {$until}",
                    paragraphs: [
                        "The paid FitAccess period for {$count} employee" . ($count === 1 ? '' : 's') . " of {$company->name} ends on {$until}.",
                        'FitAccess will send your next invoice. Pay it before that date so your employees can keep checking in without interruption.',
                    ],
                    details: [
                        'Billing preference' => BillingPeriod::label((int) ($company->billing_cycle_months ?: 1)),
                    ],
                    buttonText: 'Open billing',
                    buttonUrl: config('app.frontend_url') . '/hr/billing',
                ),
                "renewal {$company->id}"
            ));
        }

        // One summary for the FitAccess team so the renewal invoices get generated
        if ($rows) {
            $lines = array_map(fn($r) => "{$r['company']->name}: {$r['count']} employee(s), ends " . $r['until']->format('j M Y')
                . ' — prefers ' . strtolower(BillingPeriod::label((int) ($r['company']->billing_cycle_months ?: 1))), $rows);
            $admins = [];
            foreach (config('fitaccess.admin_notification_emails', []) as $email) {
                $admins[strtolower($email)] = 'FitAccess team';
            }
            self::send($admins, fn($name) => new FitAccessMail(
                emailSubject: 'Renewal invoices due: ' . count($rows) . ' compan' . (count($rows) === 1 ? 'y' : 'ies'),
                heading: 'Renewal invoices to send',
                recipientName: $name,
                paragraphs: array_merge(['These companies have employees whose paid period ends soon. Generate and send their next invoice:'], $lines),
                buttonText: 'Open billing',
                buttonUrl: config('app.frontend_url') . '/admin/billing/invoices',
            ), 'renewal summary');
        }

        return $sent;
    }
}
