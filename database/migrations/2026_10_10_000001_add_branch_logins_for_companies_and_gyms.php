<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Company branches (the company record itself is the main / head-office branch)
        if (!Schema::hasTable('company_branches')) {
            Schema::create('company_branches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('name');
                $table->string('contact_person')->nullable();
                $table->string('city')->nullable();
                $table->string('sub_city')->nullable();
                $table->string('address')->nullable();
                $table->string('landmark')->nullable();
                $table->string('phone')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // A branch login is a company_hr user pinned to one branch
        if (!Schema::hasColumn('users', 'company_branch_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('company_branch_id')->nullable()->after('company_id')->index();
            });
        }

        // Which branch an employee belongs to (null = head office)
        if (!Schema::hasColumn('employees', 'company_branch_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->unsignedBigInteger('company_branch_id')->nullable()->after('company_id')->index();
            });
        }

        // The login created for a branch that an existing gym owner requested
        if (!Schema::hasColumn('partner_applications', 'branch_user_id')) {
            Schema::table('partner_applications', function (Blueprint $table) {
                $table->unsignedBigInteger('branch_user_id')->nullable()->after('parent_gym_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('partner_applications', function (Blueprint $table) {
            $table->dropColumn('branch_user_id');
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('company_branch_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('company_branch_id');
        });
        Schema::dropIfExists('company_branches');
    }
};
