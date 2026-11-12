<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            // Which branch the invoice is for (null together with branch_scoped = head office)
            if (!Schema::hasColumn('billing_invoices', 'company_branch_id')) {
                $table->unsignedBigInteger('company_branch_id')->nullable()->after('company_id')->index();
            }
            // false = legacy / company-wide invoice (covers every employee of the company)
            if (!Schema::hasColumn('billing_invoices', 'branch_scoped')) {
                $table->boolean('branch_scoped')->default(false)->after('company_branch_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->dropColumn(['company_branch_id', 'branch_scoped']);
        });
    }
};
