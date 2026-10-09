<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            // Raw browser User-Agent, so the admin can spot bots that spoof a real
            // browser UA (the write-time BotDetector only drops self-identifying ones).
            $table->string('user_agent', 255)->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('user_agent');
        });
    }
};
