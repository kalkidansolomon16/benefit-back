<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-editable business rules, stored in `system_settings` with defaults.
 * Values are cached for a minute; saving clears the cache.
 *
 * Defaults follow the FitAccess operating calendar (management, Nov 2026):
 * staff list by the 20th → invoices drafted 21st–24th, sent on the 25th →
 * payment due on the 30th (in advance) → reminders 1st/3rd/5th → grace ends
 * on the 7th, access paused from the 8th.
 */
class Settings
{
    /** key => [default, label, help] */
    public const RULES = [
        // Company invoices
        'staff_list_cutoff_day'  => [20, 'HR confirms the staff list by (day of month)', 'The next month\'s invoice is built only from the list on this day. Employees added later become active the next working day; their extra days go on the following invoice.'],
        'invoice_draft_day'      => [21, 'Invoices are drafted on (day)', 'Draft invoices for the next month are created automatically for finance to check.'],
        'invoice_send_day'       => [25, 'Invoices are sent by (day)', 'Admins are reminded if a draft is still unsent on this day.'],
        'payment_due_day'        => [30, 'Payment due on (day of the month before service)', 'Companies pay in advance. In shorter months the last day of the month is used.'],
        'grace_end_day'          => [7,  'Grace period ends on (day of the service month)', 'Reminders go out on the 1st, 3rd and 5th. Access is paused from the next day and the invoice closes.'],
        'late_fee_percent'       => [0,  'Late fee (%)', 'Added once when an invoice is still unpaid after the due date. 0 = no fee during the grace period.'],
        'negotiation_extension_days' => [3, 'Extra days given when an exception is approved', 'A closed invoice stays open for this many days after approval.'],
        // Registration
        'company_signup_deadline_day' => [15, 'Companies register and sign by (day) to start on the 1st', 'Approved later, the company starts on the 1st of the month after next.'],
        'partner_apply_deadline_day'  => [10, 'Partner centers apply by (day)', 'They are approved and listed by the listing day and receive members from the 1st.'],
        'partner_listing_day'         => [22, 'Partner centers approved and listed by (day)', ''],
        'invite_link_days'            => [7,  'Days an employee has to activate the app after the invitation', 'The employee can ask HR to resend it.'],
        // Partner statements
        'partner_statement_day'  => [7,  'Partner statements sent by (day)', 'Statement of visits and amount due for the previous month.'],
        'partner_payment_day'    => [10, 'Partners paid by (day)', 'Partners send their VAT/TOT invoice within 3 working days of the statement.'],
        // Renewal reminder for multi-month cycles
        'renewal_reminder_days'  => [7,  'Renewal reminder (days before a paid period ends)', 'Company is reminded to confirm its staff list for the next period.'],
    ];

    private const CACHE_KEY = 'system_settings:v2';

    public static function all(): array
    {
        $stored = Cache::remember(self::CACHE_KEY, 60, function () {
            if (!Schema::hasTable('system_settings')) {
                return [];
            }

            return DB::table('system_settings')->pluck('value', 'key')->all();
        });

        $out = [];
        foreach (self::RULES as $key => [$default]) {
            $out[$key] = array_key_exists($key, $stored) && $stored[$key] !== null && $stored[$key] !== ''
                ? (is_int($default) ? (int) $stored[$key] : $stored[$key])
                : $default;
        }
        // Non-rule values (e.g. uploaded contract path)
        foreach ($stored as $key => $value) {
            $out[$key] ??= $value;
        }

        return $out;
    }

    public static function get(string $key, $default = null)
    {
        $all = self::all();

        return $all[$key] ?? $default ?? (self::RULES[$key][0] ?? null);
    }

    /**
     * Mobile app store links (Admin → Rules & Documents), for emails.
     *
     * @return array<string, string> button label => URL (only the links that are set)
     */
    public static function appLinks(): array
    {
        $all = self::all();

        return array_filter([
            'Get it on Google Play'     => $all['mobile_app_android_url'] ?? null,
            'Download on the App Store' => $all['mobile_app_ios_url'] ?? null,
        ]);
    }
    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value === null ? null : (string) $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }
        Cache::forget(self::CACHE_KEY);
    }
}
