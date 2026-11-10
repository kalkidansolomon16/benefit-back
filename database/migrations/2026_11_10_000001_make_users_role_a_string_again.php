<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026_01_01_000032_dynamic_roles turned users.role into VARCHAR(60) so
 * custom company/gym/admin roles can be stored, but the next migration
 * (000033_add_member_role_and_mobile_subscriptions) redefines it as an ENUM.
 * On a fresh database that rejects every sub-role and custom role.
 *
 * Production already has VARCHAR(60) (the migrations ran in a different
 * order there), so this only repairs fresh installs and the test database.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'mariadb') {
            return;
        }

        DB::statement("ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(60) NOT NULL DEFAULT 'employee'");
    }

    public function down(): void
    {
        // Intentionally empty: going back to an ENUM would fail on existing custom roles.
    }
};
