<?php

use App\Support\CardNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2 membership number and identity verification.
 *
 * - users.member_number: the 12-digit FitAccess number for individual
 *   members (employees use employees.card_number). Same format and number
 *   space (CardNumber, Luhn check digit). An identifier, never an entry
 *   pass. Backfilled for members who already have a plan.
 * - member_verifications: the selfie a member submits, reviewed by gym
 *   staff (against a national ID at the first visit) or by an admin.
 *   Photos live on the private disk, never under public/.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'member_number')) {
            Schema::table('users', function (Blueprint $table) {
                $table->char('member_number', 12)->nullable()->unique()->after('member_code');
            });
        }

        Schema::create('member_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('photo_path');
            $table->enum('status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->string('rejection_reason', 255)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_at_gym_id')->nullable()->constrained('gyms')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        DB::table('users')
            ->whereNull('member_number')
            ->whereIn('id', DB::table('mobile_subscriptions')->select('user_id'))
            ->orderBy('id')
            ->pluck('id')
            ->each(fn ($id) => DB::table('users')->where('id', $id)->update(['member_number' => CardNumber::generate()]));
    }

    public function down(): void
    {
        Schema::dropIfExists('member_verifications');
        if (Schema::hasColumn('users', 'member_number')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['member_number']);
                $table->dropColumn('member_number');
            });
        }
    }
};
