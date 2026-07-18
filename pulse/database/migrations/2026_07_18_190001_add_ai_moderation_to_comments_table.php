<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->string('ai_reason')->nullable();      // why the AI approved/held/rejected
            $table->timestamp('moderated_at')->nullable(); // when auto-moderation ran
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn(['ai_reason', 'moderated_at']);
        });
    }
};
