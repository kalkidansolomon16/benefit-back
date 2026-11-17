<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee portal v2:
 *  - card activation: HR hands the (auto-generated) card number to the employee,
 *    who enters it once to unlock the portal. Until then the portal is view-only.
 *  - news posts published by the FitAccess admin (optionally for one company)
 *  - trainings = wellness programs, now with an image, video and duration
 *  - content.view / content.manage admin permissions
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('employees', 'card_activated_at')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->timestamp('card_activated_at')->nullable()->after('card_number');
            });

            // Employees who already use FitAccess keep working: treat their card
            // as activated. New employees must enter the card number HR sends them.
            DB::table('employees')
                ->where('is_enrolled', true)
                ->whereNotNull('card_number')
                ->update(['card_activated_at' => DB::raw('COALESCE(enrolled_at, created_at, NOW())')]);
        }

        if (!Schema::hasTable('news_posts')) {
            Schema::create('news_posts', function (Blueprint $table) {
                $table->id();
                $table->string('title', 200);
                $table->string('category', 50)->default('News');
                $table->string('excerpt', 300)->nullable();
                $table->text('body')->nullable();
                $table->string('image_path')->nullable();
                $table->string('link_url', 500)->nullable();
                $table->string('link_label', 60)->nullable();
                // null = every company's employees
                $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
                // shown in the dashboard banner carousel
                $table->boolean('is_featured')->default(false);
                $table->boolean('is_published')->default(true);
                $table->timestamp('published_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['is_published', 'published_at']);
            });
        }

        Schema::table('wellness_programs', function (Blueprint $table) {
            if (!Schema::hasColumn('wellness_programs', 'image_path')) {
                $table->string('image_path')->nullable()->after('description');
            }
            if (!Schema::hasColumn('wellness_programs', 'video_url')) {
                $table->string('video_url', 500)->nullable()->after('image_path');
            }
            if (!Schema::hasColumn('wellness_programs', 'duration_minutes')) {
                $table->unsignedSmallInteger('duration_minutes')->nullable()->after('video_url');
            }
        });

        $now = now();
        foreach ([
            ['name' => 'content.view',   'label' => 'View News & Trainings',   'group_name' => 'Content', 'scope' => 'admin'],
            ['name' => 'content.manage', 'label' => 'Manage News & Trainings', 'group_name' => 'Content', 'scope' => 'admin'],
        ] as $p) {
            if (Schema::hasTable('permissions') && !DB::table('permissions')->where('name', $p['name'])->exists()) {
                DB::table('permissions')->insert($p + ['created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->whereIn('name', ['content.view', 'content.manage'])->delete();
        }
        Schema::table('wellness_programs', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'video_url', 'duration_minutes']);
        });
        Schema::dropIfExists('news_posts');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('card_activated_at');
        });
    }
};
