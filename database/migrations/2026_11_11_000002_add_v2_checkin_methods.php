<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v2 check-in methods: 'desk_code' (6 digits typed by reception) and
 * 'manual' (member number plus a national ID check by staff).
 * Sets the full list explicitly, whatever state the column is in.
 */
return new class extends Migration {
    private const METHODS = ['fan_number', 'card', 'facial', 'qr_code', 'desk_code', 'manual'];

    public function up(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $list = implode(',', array_map(fn ($m) => "'{$m}'", self::METHODS));
        DB::statement("ALTER TABLE checkins MODIFY COLUMN method ENUM({$list}) NOT NULL DEFAULT 'fan_number'");
    }

    public function down(): void
    {
        // Left as is: shrinking the ENUM would fail on rows already using
        // the new methods.
    }
};
