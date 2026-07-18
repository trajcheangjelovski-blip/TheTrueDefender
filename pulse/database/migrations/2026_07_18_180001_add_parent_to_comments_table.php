<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            // Self-referencing: a reply points at the comment it answers.
            // Threading is flattened to one level (replies to replies attach
            // to the same top-level parent) to stay readable on mobile.
            $table->foreignId('parent_id')->nullable()->after('post_id')
                ->constrained('comments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
