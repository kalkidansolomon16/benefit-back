<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gyms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->text('address');
            $table->string('sub_city')->nullable();
            $table->string('city')->default('Addis Ababa');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->enum('tier', ['premium','basic_plus','basic']);
            $table->integer('max_capacity')->default(200);
            $table->integer('current_members')->default(0);
            $table->decimal('monthly_fee_etb', 10, 2)->default(0);
            $table->decimal('quarterly_fee_etb', 10, 2)->nullable();
            $table->decimal('annual_fee_etb', 10, 2)->nullable();
            $table->json('facilities')->nullable();
            $table->json('opening_hours')->nullable();
            $table->string('logo_path')->nullable();
            $table->json('photo_paths')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_partner')->default(false);
            $table->date('partnership_start')->nullable();
            $table->date('partnership_end')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
    public function down(): void { Schema::dropIfExists('gyms'); }
};
