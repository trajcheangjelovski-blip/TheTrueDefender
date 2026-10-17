<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A rewritten draft of a PUBLISHED article, produced by the editorial pipeline
 * (posts:revise-live) and held for editorial approval. Nothing reaches the live
 * article until apply() is called by an editor; rollback() restores the exact
 * original from the stored snapshot.
 *
 * Statuses: pending_review (ready for an editor), needs_research, needs_rewrite,
 * rejected (insufficient evidence / editor rejected), applied, rolled_back.
 */
class PostRevision extends Model
{
    /** Post fields captured in the snapshot and restored on rollback. */
    public const SNAPSHOT_FIELDS = [
        'title', 'excerpt', 'body', 'meta_title', 'meta_description', 'focus_keyword',
        'takeaways', 'sources', 'story_type', 'policy_version', 'fact_check', 'review_status',
    ];

    protected $fillable = [
        'post_id', 'status', 'policy_version', 'story_type', 'title', 'excerpt', 'body',
        'meta_title', 'meta_description', 'takeaways', 'sources', 'fact_check', 'change_log',
        'snapshot', 'ai_usage', 'reviewed_by', 'applied_at', 'rolled_back_at',
    ];

    protected $casts = [
        'takeaways' => 'array',
        'sources' => 'array',
        'fact_check' => 'array',
        'change_log' => 'array',
        'snapshot' => 'array',
        'ai_usage' => 'array',
        'applied_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public static function snapshotOf(Post $post): array
    {
        return collect(self::SNAPSHOT_FIELDS)->mapWithKeys(fn ($f) => [$f => $post->getAttribute($f)])->all();
    }

    /**
     * Publish this revision to the live article. Keeps the post's ID, slug,
     * publication date, image, author and category; confirmed corrections are
     * disclosed in a dated correction note (never a silent change of history).
     */
    public function apply(?int $editorId = null): void
    {
        // Only a revision that passed the independent fact-check can go live; one
        // flagged needs_research / needs_rewrite / rejected never can.
        if ($this->status !== 'pending_review') {
            throw new \RuntimeException("Revision #{$this->id} is {$this->status} and cannot be applied.");
        }

        DB::transaction(function () use ($editorId) {
            $post = $this->post()->lockForUpdate()->firstOrFail();

            $post->forceFill([
                'title' => $this->title,
                'excerpt' => $this->excerpt ?? $post->excerpt,
                'body' => $this->body,
                'meta_title' => $this->meta_title ?? $post->meta_title,
                'meta_description' => $this->meta_description ?? $post->meta_description,
                'takeaways' => $this->takeaways ?? [],
                'sources' => $this->sources ?? $post->sources,
                'story_type' => $this->story_type,
                'policy_version' => $this->policy_version,
                'fact_check' => $this->fact_check,
                'review_status' => 'ready',
            ])->save();   // updated_at moves; published_at, slug, image, author, category do not

            try {
                app(\App\Services\SeoOptimizer::class)->optimizePost($post);   // re-add internal links
            } catch (\Throwable) {
            }

            // The correction note goes on AFTER link enrichment (which re-sanitizes
            // the body and would strip the note's class).
            $corrections = array_values(array_filter((array) ($this->getAttribute('change_log')['corrections'] ?? [])));
            if ($corrections) {
                $date = now(config('app.display_timezone', 'America/New_York'))->format('F j, Y');
                $post->forceFill(['body' => $post->fresh()->body
                    . '<p class="correction-note"><em>Correction (' . e($date) . '): '
                    . e(implode(' ', $corrections)) . '</em></p>'])->saveQuietly();
            }

            $this->forceFill(['status' => 'applied', 'applied_at' => now(), 'reviewed_by' => $editorId])->save();
        });
    }

    /** Restore the article exactly as it was before this revision was applied. */
    public function rollback(): void
    {
        if ($this->status !== 'applied') {
            throw new \RuntimeException("Revision #{$this->id} is {$this->status}; only an applied revision can be rolled back.");
        }

        DB::transaction(function () {
            $post = $this->post()->lockForUpdate()->firstOrFail();
            $post->forceFill(array_intersect_key((array) $this->getAttribute('snapshot'), array_flip(self::SNAPSHOT_FIELDS)))->save();
            $this->forceFill(['status' => 'rolled_back', 'rolled_back_at' => now()])->save();
        });
    }

    public function reject(?int $editorId = null): void
    {
        $this->forceFill(['status' => 'rejected', 'reviewed_by' => $editorId])->save();
    }
}
