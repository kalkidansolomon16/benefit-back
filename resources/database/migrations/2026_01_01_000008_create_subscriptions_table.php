<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->references('id')->on('membership_plans');
            $table->integer('employee_count');
            $table->decimal('total_amount_etb', 12, 2);
            $table->decimal('service_fee_etb', 12, 2);
            $table->decimal('absenteeism_fee_etb', 12, 2)->default(0);
            $table->enum('billing_cycle', ['monthly','quarterly','annual'])->default('quarterly');
            $table->date('billing_date');
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('status', ['active','pending','expired','cancelled'])->default('pending');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id','status']);
        });
    }
    public function down(): void { Schema::dropIfExists('subscriptions'); }
};
