<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('category', 32)->nullable()->after('content');   // 固定四類，見 Announcement::CATEGORY_LABELS
            $table->text('body')->nullable()->after('category');            // 富文本（RichEditor HTML）
            $table->string('link_url', 500)->nullable()->after('body');
            $table->boolean('is_pinned')->default(false)->after('is_active');
            $table->timestamp('published_at')->nullable()->after('is_pinned');
            $table->index(['published_at', 'is_pinned']);
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex(['published_at', 'is_pinned']);
            $table->dropColumn(['category', 'body', 'link_url', 'is_pinned', 'published_at']);
        });
    }
};
