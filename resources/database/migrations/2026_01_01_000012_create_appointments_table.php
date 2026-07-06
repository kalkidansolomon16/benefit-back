<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('doctor_name')->nullable();
            $table->string('clinic_name')->nullable();
            $table->string('specialty')->nullable();
            $table->datetime('appointment_at');
            $table->enum('status', ['pending','confirmed','completed','cancelled','no_show'])->default('pending');
            $table->text('notes')->nullable();
            $table->string('meeting_link')->nullable();
            $table->boolean('is_online')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id','appointment_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('appointments'); }
};
