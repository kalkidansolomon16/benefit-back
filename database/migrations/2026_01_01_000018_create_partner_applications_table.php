<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('partner_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Business identity
            $table->string('facility_name');
            $table->json('categories');              // e.g. ["gym","swimming","spa"]
            $table->string('contact_person');
            $table->string('contact_phone');
            $table->string('contact_email');
            $table->string('tin_number');
            $table->string('business_license_path')->nullable();

            // Location
            $table->string('city');
            $table->string('sub_city')->nullable();
            $table->string('woreda');
            $table->string('landmark')->nullable();
            $table->string('google_maps_link')->nullable();

            // Operations
            $table->string('weekday_open')->nullable();   // "06:00"
            $table->string('weekday_close')->nullable();  // "22:00"
            $table->string('weekend_open')->nullable();
            $table->string('weekend_close')->nullable();
            $table->string('operating_hours_summary')->nullable();
            $table->unsignedInteger('max_capacity')->default(0);
            $table->json('amenities')->nullable();

            // Status
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_applications');
    }
};
