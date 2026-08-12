<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingested_items', function (Blueprint $table) {
            // AI editorial pre-screen score (0-100). Recorded for every gated item
            // so the decision (publish / skip) is visible and the threshold tunable.
            $table->unsignedTinyInteger('editorial_score')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ingested_items', function (Blueprint $table) {
            $table->dropColumn('editorial_score');
        });
    }
};
