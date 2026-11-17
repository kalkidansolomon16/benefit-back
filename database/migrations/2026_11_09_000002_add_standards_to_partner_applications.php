<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_applications', function (Blueprint $table) {
            if (!Schema::hasColumn('partner_applications', 'website')) {
                $table->string('website', 255)->nullable()->after('tin_number');
            }
            // Facility standards answered on the application form (null on older applications)
            foreach (['has_permits', 'has_certified_equipment', 'has_reception'] as $col) {
                if (!Schema::hasColumn('partner_applications', $col)) {
                    $table->boolean($col)->nullable()->after('amenities');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('partner_applications', function (Blueprint $table) {
            $table->dropColumn(['website', 'has_permits', 'has_certified_equipment', 'has_reception']);
        });
    }
};
