<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('fan_number')->unique();
            $table->string('photo_path')->nullable();
            $table->string('job_title')->nullable();
            $table->enum('level', ['chief','director','manager','staff'])->default('staff');
            $table->string('department')->nullable();
            $table->boolean('is_enrolled')->default(false);
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('employees'); }
};
