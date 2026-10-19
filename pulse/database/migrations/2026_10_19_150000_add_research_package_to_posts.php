<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The research desk's structured package (App\Services\ResearchAnalyst):
     * event identity, dates, verified facts with packet references, conflicts,
     * newsworthiness and the deterministic assessment. event_key / event_date
     * are its identity, indexed for event-based duplicate detection.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->json('research_package')->nullable()->after('fact_check');
            $table->string('event_key', 120)->nullable()->index()->after('research_package');
            $table->date('event_date')->nullable()->after('event_key');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['event_key']);
            $table->dropColumn(['research_package', 'event_key', 'event_date']);
        });
    }
};
