<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // All available permissions in the system
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();      // e.g. "billing.view"
            $table->string('label', 80);               // e.g. "View Billing"
            $table->string('group_name', 60);          // e.g. "Billing"
            $table->enum('scope', ['admin', 'company', 'gym']);
            $table->string('description', 200)->nullable();
            $table->timestamps();
        });

        // Default permissions assigned to each role (editable by admin)
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role', 40);
            $table->string('permission_name', 80);
            $table->timestamps();
            $table->unique(['role', 'permission_name'], 'rp_role_perm_unique');
            $table->index('role');
        });

        // Per-user permission overrides (grant or revoke individually)
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('permission_name', 80);
            $table->boolean('granted')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'permission_name'], 'up_user_perm_unique');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
    }
};
