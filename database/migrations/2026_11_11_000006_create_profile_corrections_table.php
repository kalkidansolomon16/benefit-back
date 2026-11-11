<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2: members can't edit their name or other identity details in the
 * app. They ask for a correction instead (the right to have wrong
 * personal data corrected), and an admin reviews it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('profile_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('field', 40);
            $table->string('current_value', 255)->nullable();
            $table->string('requested_value', 255);
            $table->string('note', 500)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('review_note', 255)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_corrections');
    }
};
