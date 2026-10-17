<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Editorial pipeline bookkeeping (additive only — no existing column changes):
     *  - posts: which policy version wrote it, its story type, the independent
     *    fact-check result + review status, and AI token usage.
     *  - post_revisions: rewritten drafts of published articles awaiting editorial
     *    approval, each with a full snapshot of the original for rollback.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('policy_version', 40)->nullable();
            $table->string('story_type', 30)->nullable();
            $table->string('review_status', 30)->nullable()->index();
            $table->json('fact_check')->nullable();
            $table->json('ai_usage')->nullable();
        });

        Schema::create('post_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->index();          // pending_review | needs_research | needs_rewrite | rejected | applied | rolled_back
            $table->string('policy_version', 40);
            $table->string('story_type', 30)->nullable();
            $table->string('title');
            $table->text('excerpt')->nullable();
            $table->longText('body');
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 300)->nullable();
            $table->json('takeaways')->nullable();
            $table->json('sources')->nullable();
            $table->json('fact_check')->nullable();
            $table->json('change_log')->nullable();         // {summary, corrections[], original_issues[]}
            $table->json('snapshot');                       // the post before this revision (for rollback)
            $table->json('ai_usage')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
            $table->index(['post_id', 'policy_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_revisions');
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['review_status']);
            $table->dropColumn(['policy_version', 'story_type', 'review_status', 'fact_check', 'ai_usage']);
        });
    }
};
