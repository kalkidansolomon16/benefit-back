<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly partner (gym) statements:
 *   draft → sent (to the gym) → invoiced (gym uploads its VAT/TOT invoice) → paid (FitAccess uploads the receipt)
 *
 * Lines are per member plan tier: visits × the gym's rate for that tier.
 * gyms.visit_rates holds per-tier rates; missing tiers use gyms.per_visit_rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('partner_statements')) {
            Schema::create('partner_statements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('gym_id')->constrained()->cascadeOnDelete();
                $table->string('period', 7);               // YYYY-MM
                $table->date('period_start');
                $table->date('period_end');
                $table->unsignedInteger('total_visits')->default(0);
                $table->unsignedInteger('unique_members')->default(0);
                $table->decimal('total_amount', 12, 2)->default(0);
                $table->json('lines')->nullable();
                $table->string('status', 20)->default('draft'); // draft | sent | invoiced | paid
                $table->timestamp('sent_at')->nullable();
                $table->date('invoice_due_on')->nullable();
                $table->string('partner_invoice_path')->nullable();
                $table->string('partner_invoice_name')->nullable();
                $table->string('partner_invoice_number', 80)->nullable();
                $table->string('partner_invoice_type', 10)->nullable();   // VAT | TOT
                $table->decimal('partner_invoice_amount', 12, 2)->nullable();
                $table->text('partner_note')->nullable();
                $table->timestamp('invoiced_at')->nullable();
                $table->string('receipt_path')->nullable();
                $table->string('receipt_name')->nullable();
                $table->string('payment_reference', 120)->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('admin_note')->nullable();
                $table->timestamps();
                $table->unique(['gym_id', 'period']);
                $table->index(['status', 'period']);
            });
        }

        if (!Schema::hasColumn('gyms', 'visit_rates')) {
            Schema::table('gyms', function (Blueprint $table) {
                $table->json('visit_rates')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_statements');
        if (Schema::hasColumn('gyms', 'visit_rates')) {
            Schema::table('gyms', fn (Blueprint $t) => $t->dropColumn('visit_rates'));
        }
    }
};
