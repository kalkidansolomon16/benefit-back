<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * users.role was an ENUM that only listed the original roles, so team members
 * with any other role (admin_finance, admin_support, custom admin / company /
 * gym roles) could not be saved: strict MySQL rejected them and non-strict
 * MySQL stored an empty role, which made the member vanish from team lists.
 * Roles are managed in the `roles` table, so the column is a plain string.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return; // sqlite / others already store any text
        }

        DB::statement("ALTER TABLE users MODIFY COLUMN role VARCHAR(60) NOT NULL DEFAULT 'employee'");
    }

    public function down(): void
    {
        // Keep the string column: going back to the ENUM would wipe every newer role.
    }
};
