<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Each column is guarded so the migration can be re-run safely
        // (MySQL cannot roll back schema changes if it fails half-way).
        Schema::table('gyms', function (Blueprint $table) {
            // The gym_partner user who owns this gym (and all its branches)
            if (!Schema::hasColumn('gyms', 'owner_user_id')) {
                $table->unsignedBigInteger('owner_user_id')->nullable()->after('id')->index();
            }
            // Set on branches: points to the main location
            if (!Schema::hasColumn('gyms', 'parent_gym_id')) {
                $table->unsignedBigInteger('parent_gym_id')->nullable()->after('owner_user_id')->index();
            }
            if (!Schema::hasColumn('gyms', 'branch_name')) {
                $table->string('branch_name')->nullable()->after('name');
            }
        });

        Schema::table('partner_applications', function (Blueprint $table) {
            // Set when an existing partner requests a new branch from their portal
            if (!Schema::hasColumn('partner_applications', 'parent_gym_id')) {
                $table->unsignedBigInteger('parent_gym_id')->nullable()->after('user_id')->index();
            }
            if (!Schema::hasColumn('partner_applications', 'branch_name')) {
                $table->string('branch_name')->nullable()->after('facility_name');
            }
            // Extra branches submitted with the initial registration
            if (!Schema::hasColumn('partner_applications', 'branches')) {
                $table->json('branches')->nullable()->after('amenities');
            }
        });

        // Link existing partner gyms to their owner account (previously matched by email only)
        DB::statement("
            UPDATE gyms
            SET owner_user_id = (
                SELECT u.id FROM users u
                WHERE u.email = gyms.contact_email AND u.role = 'gym_partner'
                ORDER BY u.id LIMIT 1
            )
            WHERE owner_user_id IS NULL AND contact_email IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::table('partner_applications', function (Blueprint $table) {
            $table->dropColumn(['parent_gym_id', 'branch_name', 'branches']);
        });

        Schema::table('gyms', function (Blueprint $table) {
            $table->dropColumn(['owner_user_id', 'parent_gym_id', 'branch_name']);
        });
    }
};
