<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Editors flag a story "developing" to surface the article-level
            // "Follow this story" alert opt-in. Off by default so it only shows
            // where it's genuinely a live, moving story.
            $table->boolean('is_developing')->default(false)->after('is_trending');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('is_developing');
        });
    }
};
