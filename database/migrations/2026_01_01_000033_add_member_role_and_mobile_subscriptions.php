<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add 'member' role for B2C individual consumers
        // MySQL approach — modify enum column
        DB::statement("
            ALTER TABLE users MODIFY COLUMN role ENUM(
                'super_admin',
                'fitaccess_admin',
                'company_hr',
                'employee',
                'gym_staff',
                'gym_partner',
                'member'
            ) NOT NULL
        ");

        // Create mobile_subscriptions table for B2C members
        // (separate from the B2B company subscriptions table)
        Schema::create('mobile_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->references('id')->on('membership_plans');
            $table->enum('status', ['active', 'cancelled', 'expired'])->default('active');
            $table->enum('billing_cycle', ['monthly', 'annual'])->default('monthly');
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->string('payment_reference')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('auto_renew')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE users MODIFY COLUMN role ENUM(
                'super_admin',
                'fitaccess_admin',
                'company_hr',
                'employee',
                'gym_staff',
                'gym_partner'
            ) NOT NULL
        ");

        Schema::dropIfExists('mobile_subscriptions');
    }
};
