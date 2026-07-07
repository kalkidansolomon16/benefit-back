<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gym_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->references('id')->on('membership_plans');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['active','suspended','expired','cancelled'])->default('active');
            $table->string('suspension_reason')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id','status']);
            $table->index(['gym_id','status']);
        });
    }
    public function down(): void { Schema::dropIfExists('memberships'); }
};
