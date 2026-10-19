<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outlets (hosts) whose feeds carried this story — ours plus every other
     * outlet whose copy was detected as a duplicate. More outlets = a bigger
     * story: App\Support\StoryRank adds +5 per extra outlet (max +15).
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->json('coverage_outlets')->nullable()->after('sources');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('coverage_outlets');
        });
    }
};
