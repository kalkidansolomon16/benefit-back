<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_wellness', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->references('id')->on('wellness_programs');
            $table->enum('status', ['enrolled','completed','dropped'])->default('enrolled');
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['employee_id','program_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('employee_wellness'); }
};
