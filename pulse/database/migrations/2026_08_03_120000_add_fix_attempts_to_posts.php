<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // How many times the draft-fixer has retried this post — lets the
            // 5-minute retry loop give up on genuinely un-fixable drafts.
            $table->unsignedTinyInteger('fix_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('fix_attempts');
        });
    }
};
