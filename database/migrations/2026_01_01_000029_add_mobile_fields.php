<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add mobile-specific columns to gyms table
        Schema::table('gyms', function (Blueprint $table) {
            $table->string('category', 50)->default('gym')->after('name');
            // gym | pool | spa | cinema | theatre
            $table->string('photo_url', 500)->nullable()->after('logo_path');
            $table->string('cover_photo_url', 500)->nullable()->after('photo_url');
            $table->string('partner_code', 20)->nullable()->unique()->after('cover_photo_url');
            $table->decimal('per_visit_rate', 8, 2)->default(0)->after('partner_code');
            $table->unsignedInteger('quality_score')->default(50)->after('per_visit_rate');
            $table->json('amenities')->nullable()->after('facilities');
        });

        // Add member_code to users table
        Schema::table('users', function (Blueprint $table) {
            $table->string('member_code', 20)->nullable()->unique()->after('fan_number');
            $table->boolean('must_change_password')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('gyms', function (Blueprint $table) {
            $table->dropColumn([
                'category', 'photo_url', 'cover_photo_url',
                'partner_code', 'per_visit_rate', 'quality_score', 'amenities',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['member_code', 'must_change_password']);
        });
    }
};
