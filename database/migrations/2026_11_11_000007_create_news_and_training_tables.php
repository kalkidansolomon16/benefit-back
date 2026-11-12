<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2 content: news articles (Home carousel and News page) and training
 * videos (Training tab; playback for premium plans only). Videos are
 * hosted on a video service; only their URLs are stored here.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('news_articles', function (Blueprint $table) {
            $table->id();
            $table->enum('category', ['partner_news', 'wellness_tips', 'offers', 'announcements']);
            $table->string('title', 160);
            $table->string('excerpt', 300);
            // Paragraphs; "## " starts a sub-heading, "• " a list item.
            $table->json('body');
            $table->string('image_path')->nullable();
            $table->unsignedTinyInteger('read_minutes')->default(2);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_published', 'published_at']);
        });

        Schema::create('training_videos', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->string('category', 60);
            $table->string('level', 40);
            $table->string('coach', 80);
            $table->unsignedSmallInteger('minutes');
            $table->text('description');
            $table->json('equipment')->nullable();
            $table->json('what_you_need')->nullable();
            $table->string('thumbnail_path')->nullable();
            // Streaming URL on the video service; returned only to premium plans.
            $table->string('video_url', 500)->nullable();
            $table->boolean('is_premium')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['is_published', 'category', 'sort']);
        });

        Schema::create('training_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_video_id')->constrained()->cascadeOnDelete();
            $table->decimal('progress', 4, 3)->default(0);
            $table->boolean('saved')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'training_video_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_progress');
        Schema::dropIfExists('training_videos');
        Schema::dropIfExists('news_articles');
    }
};
