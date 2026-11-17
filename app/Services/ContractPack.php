<?php

namespace App\Services;

use App\Models\Company;
use App\Support\BillingCalendar;
use App\Support\Settings;
use Illuminate\Support\Carbon;

/**
 * Sent to a company when FitAccess approves it (and again on request):
 * the Employee Enrolment and Consent Form + the Excel template, with the
 * staff-list deadline and the payment calendar.
 *
 * Companies that complete registration by the 15th start on the 1st of next
 * month; later ones on the 1st of the month after. Their staff list is due on
 * the 20th of the month before they start.
 */
class ContractPack
{
    /** First service month for a company approved on $day */
    public static function startMonth(?Carbon $day = null): Carbon
    {
        $day = ($day ?? now())->copy()->startOfDay();
        $months = $day->day <= Settings::int('company_signup_deadline_day') ? 1 : 2;

        return $day->copy()->startOfMonth()->addMonthsNoOverflow($months);
    }

    public static function send(Company $company): void
    {
        $start = self::startMonth();
        $deadline = BillingCalendar::staffListDeadline($start);
        if ($deadline->lt(now()->startOfDay())) {
            $deadline = BillingCalendar::staffListDeadline($start->copy()->addMonthNoOverflow());
            $start->addMonthNoOverflow();
        }
        $company->update(['contract_sent_at' => now(), 'import_deadline' => $deadline->toDateString()]);

        $contract = OnboardingDocuments::contract();
        $o = [BillingCalendar::class, 'ord'];

        Notifier::company($company, 'contract_pack', 'Your FitAccess consent form and employee template',
            "Give the Employee Enrolment and Consent Form to your employees. Import the Excel list of those who signed by {$deadline->format('j M Y')} so they start on {$start->format('j M Y')}.",
            [
                'subject'    => "FitAccess: employee consent form and staff-list template — staff list due {$deadline->format('j M Y')}",
                'heading'    => 'Next step: enrol your employees',
                'highlight'  => "Staff list due: {$deadline->format('j M Y')} · Service starts: {$start->format('j M Y')}",
                'paragraphs' => [
                    "Welcome to FitAccess, {$company->name}. Attached you will find the Employee Enrolment and Consent Form (FA-EMP-01) and the Excel template for your staff list.",
                    'Give the form to every employee who wants to join. They complete it, read the privacy notice and member rules, and sign. Keep the signed originals at your company.',
                    'Fill in the Excel template with every employee who signed (including each person\'s package) and import it in the HR portal under Employees → Import. Each employee then receives an e-mail to activate the app within ' . Settings::int('invite_link_days') . ' days.',
                ],
                'steps'      => [
                    "Import your staff list by {$deadline->format('j M Y')}. Your invoice is built only from that list.",
                    'FitAccess prepares the invoice and sends it by the ' . call_user_func($o, BillingCalendar::sendDay()) . ' (monthly by default; you can choose 3, 6 or 12 months on the Billing page).',
                    'Pay by ' . BillingCalendar::dueDate($start)->format('j M Y') . ', in advance. The grace period ends on ' . BillingCalendar::closesOn($start)->format('j M Y') . '.',
                    'FitAccess activates your employees and e-mails each of them their own card number. You do not need to forward anything.',
                ],
                'details'    => [
                    'Every month'       => 'Staff list by the ' . call_user_func($o, BillingCalendar::cutoffDay()) . ' · invoice by the ' . call_user_func($o, BillingCalendar::sendDay()) . ' · payment due on the ' . call_user_func($o, BillingCalendar::dueDay()),
                    'Reminders'         => 'On the ' . implode(', ', array_map($o, BillingCalendar::reminderDays())) . ' of the month if unpaid',
                    'Grace period'      => 'Until the ' . call_user_func($o, BillingCalendar::graceEndDay()) . '; staff access is paused from the ' . call_user_func($o, BillingCalendar::graceEndDay() + 1),
                    'Late additions'    => 'Employees added after the ' . call_user_func($o, BillingCalendar::cutoffDay()) . ' become active the next working day; extra days go on the next invoice',
                ],
                'buttonText' => 'Open the HR portal',
                'buttonUrl'  => config('app.frontend_url') . '/hr/employees',
                'closing'    => 'You can download the consent form and the Excel template at any time from the HR portal.',
                'files'      => [
                    $contract,
                    [
                        'content' => OnboardingDocuments::employeeTemplate($company),
                        'name'    => OnboardingDocuments::templateFilename($company),
                        'mime'    => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ],
                ],
            ]
        );
    }
}
