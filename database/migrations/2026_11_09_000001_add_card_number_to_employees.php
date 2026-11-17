<?php

use App\Support\CardNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('employees', 'card_number')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->char('card_number', 12)->nullable()->unique()->after('fan_number');
            });
        }

        // Give every existing employee (including archived ones) a card number.
        DB::table('employees')->whereNull('card_number')->orderBy('id')->pluck('id')
            ->each(fn ($id) => DB::table('employees')->where('id', $id)
                ->update(['card_number' => CardNumber::generate()]));
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['card_number']);
            $table->dropColumn('card_number');
        });
    }
};
