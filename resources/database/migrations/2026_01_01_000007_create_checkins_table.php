<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gym_id')->constrained();
            $table->foreignId('employee_id')->constrained();
            $table->timestamp('checked_in_at');
            $table->timestamp('checked_out_at')->nullable();
            $table->string('recorded_by')->nullable();
            $table->enum('method', ['fan_number','card','facial'])->default('fan_number');
            $table->timestamps();
            $table->index(['employee_id','checked_in_at']);
            $table->index(['gym_id','checked_in_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('checkins'); }
};
