<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_number', 40)->unique();
            $table->string('billing_period', 30);           // e.g. "May 2026"
            $table->decimal('total_amount', 12, 2);
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['draft', 'sent', 'paid', 'overdue'])->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoices');
    }
};
