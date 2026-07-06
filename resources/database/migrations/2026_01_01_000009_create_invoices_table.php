<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained();
            $table->string('invoice_number')->unique();
            $table->decimal('subtotal_etb', 12, 2);
            $table->decimal('service_fee_etb', 12, 2);
            $table->decimal('absenteeism_fee_etb', 12, 2)->default(0);
            $table->decimal('tax_etb', 12, 2)->default(0);
            $table->decimal('total_etb', 12, 2);
            $table->date('issue_date');
            $table->date('due_date');
            $table->date('paid_at')->nullable();
            $table->enum('status', ['draft','sent','paid','overdue','cancelled'])->default('draft');
            $table->string('payment_reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('invoices'); }
};
