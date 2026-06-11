<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('telegram_chat_id')->unsigned()->nullable()->unique()->after('phone');
            $table->string('telegram_link_token', 8)->nullable()->after('telegram_chat_id');
            $table->timestamp('telegram_link_expires_at')->nullable()->after('telegram_link_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['telegram_chat_id', 'telegram_link_token', 'telegram_link_expires_at']);
        });
    }
};
