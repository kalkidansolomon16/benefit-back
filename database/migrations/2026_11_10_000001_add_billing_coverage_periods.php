<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing coverage periods.
 *
 * - companies.billing_cycle_months: how often the company prefers to pay (1, 3, 6 or 12 months)
 * - billing_invoices.period_months / period_start / period_end: the dates an invoice pays for
 * - billing_invoice_items.months: months charged on that line (subtotal = employees × monthly price × months)
 * - billing_invoice_employees: exactly which employees an invoice covers
 * - employees.paid_until: last day the employee's access is paid for; after it, access stops
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'billing_cycle_months')) {
                $table->unsignedTinyInteger('billing_cycle_months')->default(1)->after('is_active');
            }
        });

        Schema::table('billing_invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('billing_invoices', 'period_months')) {
                $table->unsignedTinyInteger('period_months')->default(1)->after('billing_period');
                $table->date('period_start')->nullable()->after('period_months');
                $table->date('period_end')->nullable()->after('period_start');
            }
        });

        Schema::table('billing_invoice_items', function (Blueprint $table) {
            if (!Schema::hasColumn('billing_invoice_items', 'months')) {
                $table->unsignedTinyInteger('months')->default(1)->after('unit_price');
            }
        });

        if (!Schema::hasTable('billing_invoice_employees')) {
            Schema::create('billing_invoice_employees', function (Blueprint $table) {
                // Plain indexed columns (no database foreign keys) so this also works
                // on hosts whose existing tables use MyISAM; rows are removed with
                // their invoice in BillingInvoice::booted().
                $table->id();
                $table->unsignedBigInteger('billing_invoice_id');
                $table->unsignedBigInteger('employee_id')->index();
                $table->timestamps();
                $table->unique(['billing_invoice_id', 'employee_id'], 'bie_invoice_employee_unique');
            });
        }

        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'paid_until')) {
                $table->date('paid_until')->nullable()->after('paid_period')->index();
            }
        });

        // ── Existing data ────────────────────────────────────────────
        $monthEnd = static function (?string $label): ?Carbon {
            if (!$label) {
                return null;
            }
            try {
                return Carbon::createFromFormat('!F Y', trim($label))->endOfMonth();
            } catch (\Throwable) {
                return null;
            }
        };

        // Old invoices were one calendar month, named like "October 2026"
        DB::table('billing_invoices')->whereNull('period_start')->orderBy('id')->get(['id', 'billing_period'])
            ->each(function ($inv) use ($monthEnd) {
                $end = $monthEnd($inv->billing_period);
                if ($end) {
                    DB::table('billing_invoices')->where('id', $inv->id)->update([
                        'period_months' => 1,
                        'period_start'  => $end->copy()->startOfMonth()->toDateString(),
                        'period_end'    => $end->toDateString(),
                    ]);
                }
            });

        // Employees already marked paid keep access at least until the end of the
        // current month, so nobody is locked out on the day this update goes live.
        $floor = now()->endOfMonth();
        DB::table('employees')->where('payment_status', 'paid')->whereNull('paid_until')
            ->orderBy('id')->get(['id', 'paid_period'])
            ->each(function ($e) use ($monthEnd, $floor) {
                $end = $monthEnd($e->paid_period);
                $until = $end && $end->gt($floor) ? $end : $floor;
                DB::table('employees')->where('id', $e->id)->update(['paid_until' => $until->toDateString()]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoice_employees');
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn('paid_until'));
        Schema::table('billing_invoice_items', fn (Blueprint $t) => $t->dropColumn('months'));
        Schema::table('billing_invoices', fn (Blueprint $t) => $t->dropColumn(['period_months', 'period_start', 'period_end']));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn('billing_cycle_months'));
    }
};
