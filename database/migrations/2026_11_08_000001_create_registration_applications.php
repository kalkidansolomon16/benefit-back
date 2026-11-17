<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Companies and gyms no longer get an account when they fill in the public
 * form: they submit an application, an admin verifies it and only then are
 * the company / gym and their logins created (with a temporary password).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('company_applications')) {
            Schema::create('company_applications', function (Blueprint $table) {
                $table->id();
                $table->string('reference', 20)->nullable()->unique();
                $table->string('company_name');
                $table->string('industry', 100);
                $table->string('company_size', 50)->nullable();
                $table->string('tin_number', 50);
                $table->string('preferred_payment_method', 50)->nullable();
                $table->string('contact_name');
                $table->string('job_title')->nullable();
                $table->string('contact_email')->index();
                $table->string('contact_phone', 20);
                $table->json('branches')->nullable();
                $table->string('status', 20)->default('pending')->index();
                $table->text('rejection_reason')->nullable();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('partner_applications', function (Blueprint $table) {
            if (!Schema::hasColumn('partner_applications', 'reference')) {
                $table->string('reference', 20)->nullable()->unique()->after('id');
            }
            // The owner's login is now created when the admin approves.
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('partner_applications', function (Blueprint $table) {
            if (Schema::hasColumn('partner_applications', 'reference')) {
                $table->dropUnique(['reference']);
                $table->dropColumn('reference');
            }
        });

        Schema::dropIfExists('company_applications');
    }
};
