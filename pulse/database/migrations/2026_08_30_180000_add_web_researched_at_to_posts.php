<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Set once the web-research pass has searched the open web for other
            // coverage of this story and synthesized what it found. NULL = not yet.
            $table->timestamp('web_researched_at')->nullable()->after('sources');
            $table->index('web_researched_at');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['web_researched_at']);
            $table->dropColumn('web_researched_at');
        });
    }
};
