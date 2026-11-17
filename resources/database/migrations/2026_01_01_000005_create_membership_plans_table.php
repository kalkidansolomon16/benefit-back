<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('tier', ['platinum','basic_plus','basic']);
            $table->decimal('monthly_fee_etb', 10, 2);
            $table->integer('duration_months')->default(1);
            $table->json('features')->nullable();
            $table->enum('target_level', ['chief','director','manager','staff','all'])->default('all');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('membership_plans'); }
};
