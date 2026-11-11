<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * notifications.type was a fixed ENUM; v2 adds member notices such as
 * 'manual_checkin' ("Your membership was used at ... Not you?"), with more
 * to come (news, verification results). Existing values are kept.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        DB::statement("ALTER TABLE notifications MODIFY COLUMN type VARCHAR(40) NOT NULL DEFAULT 'system'");
    }

    public function down(): void
    {
        // Left as is: going back to the ENUM would fail on the new types.
    }
};
