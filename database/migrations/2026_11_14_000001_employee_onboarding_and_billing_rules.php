<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company-led onboarding and the monthly payment calendar.
 *
 *  - system_settings: admin-editable rules (payment window, late fee, deadlines)
 *  - employees: birth date + invitation / account-creation tracking
 *  - companies: when the contract pack was sent and the import deadline
 *  - billing_invoices: late fee, the day the invoice closes, "closed" status
 *  - billing_negotiations: how long an approved exception keeps the invoice open
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 80)->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'birth_date')) {
                $table->date('birth_date')->nullable()->after('fan_number');
            }
            if (!Schema::hasColumn('employees', 'invited_at')) {
                $table->timestamp('invited_at')->nullable();
            }
            if (!Schema::hasColumn('employees', 'invite_expires_at')) {
                $table->timestamp('invite_expires_at')->nullable();
            }
            if (!Schema::hasColumn('employees', 'invite_token_hash')) {
                $table->string('invite_token_hash', 64)->nullable()->index();
            }
            if (!Schema::hasColumn('employees', 'account_created_at')) {
                $table->timestamp('account_created_at')->nullable();
            }
        });

        // Everybody who exists today already has a working account
        DB::table('employees')->whereNull('account_created_at')->update(['account_created_at' => DB::raw('created_at')]);

        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'contract_sent_at')) {
                $table->timestamp('contract_sent_at')->nullable();
            }
            if (!Schema::hasColumn('companies', 'import_deadline')) {
                $table->date('import_deadline')->nullable();
            }
        });

        Schema::table('billing_invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('billing_invoices', 'late_fee_amount')) {
                $table->decimal('late_fee_amount', 12, 2)->default(0)->after('total_amount');
            }
            if (!Schema::hasColumn('billing_invoices', 'late_fee_applied_at')) {
                $table->timestamp('late_fee_applied_at')->nullable();
            }
            if (!Schema::hasColumn('billing_invoices', 'closes_on')) {
                $table->date('closes_on')->nullable()->after('due_date');
            }
            if (!Schema::hasColumn('billing_invoices', 'closed_at')) {
                $table->timestamp('closed_at')->nullable();
            }
            if (!Schema::hasColumn('billing_invoices', 'last_reminder')) {
                $table->string('last_reminder', 40)->nullable();
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE billing_invoices MODIFY status ENUM('draft','sent','paid','overdue','closed') NOT NULL DEFAULT 'draft'");
        }

        Schema::table('billing_negotiations', function (Blueprint $table) {
            if (!Schema::hasColumn('billing_negotiations', 'extended_until')) {
                $table->date('extended_until')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('billing_negotiations', fn (Blueprint $t) => $t->dropColumn('extended_until'));
        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE billing_invoices SET status = 'overdue' WHERE status = 'closed'");
            DB::statement("ALTER TABLE billing_invoices MODIFY status ENUM('draft','sent','paid','overdue') NOT NULL DEFAULT 'draft'");
        }
        Schema::table('billing_invoices', fn (Blueprint $t) => $t->dropColumn(['late_fee_amount', 'late_fee_applied_at', 'closes_on', 'closed_at', 'last_reminder']));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['contract_sent_at', 'import_deadline']));
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn(['birth_date', 'invited_at', 'invite_expires_at', 'invite_token_hash', 'account_created_at']));
        Schema::dropIfExists('system_settings');
    }
};
