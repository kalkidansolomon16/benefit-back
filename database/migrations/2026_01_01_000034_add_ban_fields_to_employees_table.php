<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->timestamp('banned_until')->nullable()->after('is_enrolled');
            $table->string('ban_reason')->nullable()->after('banned_until');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['banned_until', 'ban_reason']);
        });
    }
};
