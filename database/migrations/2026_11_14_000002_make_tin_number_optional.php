<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TIN is optional for company and partner (gym) applications (client request). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_applications', function (Blueprint $table) {
            $table->string('tin_number', 50)->nullable()->change();
        });
        Schema::table('partner_applications', function (Blueprint $table) {
            $table->string('tin_number', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Leave the columns nullable: making them required again would fail on rows without a TIN.
    }
};
