<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Map-picked coordinates. `gyms` already has latitude/longitude (read by the
 * mobile app); this lets a partner application carry the pin until approval,
 * and gives company branches a pin of their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_applications', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('google_maps_link');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        Schema::table('company_branches', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('landmark');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('partner_applications', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });

        Schema::table('company_branches', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
