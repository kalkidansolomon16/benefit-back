<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employees added after the staff-list cut-off (the 20th) become active the
 * next working day (activate_on); the days until their first invoiced month
 * are charged on the next invoice (prorate_from = first unbilled day).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'activate_on')) {
                $table->date('activate_on')->nullable();
            }
            if (!Schema::hasColumn('employees', 'prorate_from')) {
                $table->date('prorate_from')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn(['activate_on', 'prorate_from']));
    }
};
