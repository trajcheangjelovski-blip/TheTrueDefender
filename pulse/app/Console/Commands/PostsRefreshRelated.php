<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\PostRevision;
use App\Services\AiClient;
use App\Services\EditorialPipeline;
use App\Services\WebResearcher;
use App\Services\FeedRelatedResearcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Find materially newer, independent reporting and PROPOSE a revised article.
 * Never changes a published article automatically. The editor must apply
 * a pending_review PostRevision from the existing Filament admin.
 */
class PostsRefreshRelated extends Command
{
    protected $signature = 'posts:refresh-related
        {--limit=1 : Max published stories to consider per run}
        {--window-hours=48 : Check only this recent publication window}
        {--dry : Show possible updates without writing revisions}';

    protected $description = 'Find newer coverage for published news, produce fact-checked review drafts (no live edits)';

    public function handle(WebResearcher $researcher, FeedRelatedResearcher $feedResearch, EditorialPipeline $pipeline, AiClient $ai): int
    {
        if (! $ai->enabled()) {
            $this->warn('No AI provider configured; no changes.');
            return self::SUCCESS;
        }

        $limit = min(5, max(1, (int) $this->option('limit')));
        $dry = (bool) $this->option('dry');
        $posts = Post::query()->where('status', 'published')
            ->whereNotNull('source_url')
            ->where('published_at', '>=', now()->subHours(max(1, (int) $this->option('window-hours'))))
            ->latest('published_at')->limit(80)->get();

        $checked = 0;
        foreach ($posts as $post) {
            if ($checked >= $limit) {
                break;
            }
            if (PostRevision::where('post_id', $post->id)
                ->whereIn('status', ['pending_review', 'needs_rewrite', 'needs_research'])->exists()) {
                continue;
            }
            // Skip posts refreshed recently; do not reset the normal research timestamp.
            $key = "post:related-refresh:{$post->id}";
            if (Cache::has($key)) {
                continue;
            }
            $checked++;

            $sources = array_merge([['url' => $post->source_url]], (array) ($post->sources ?? []));
            $hosts = array_values(array_unique(array_filter(array_map(
                fn ($s) => strtolower(preg_replace('/^www\./', '', (string) parse_url((string) ($s['url'] ?? ''), PHP_URL_HOST))),
                $sources
            ))));

            $newer = $feedResearch->research((string) $post->title, $hosts, 3, $post->published_at);
            if ($researcher->enabled() && count($newer) < 3) {
                $fresh = $researcher->newerCoverage((string) $post->title, array_merge($hosts, array_map(
                    fn ($s) => strtolower((string) parse_url($s['url'], PHP_URL_HOST)), $newer
                )), $post->published_at, 3 - count($newer));
                foreach ($fresh as $source) {
                    $page = app(\App\Services\ArticleFetcher::class)->extract($source['url']);
                    if (str_word_count((string) ($page['text'] ?? '')) >= 150) {
                        $newer[] = ['text' => $page['text']] + $source;
                    }
                }
            }
            $this->line("#{$post->id} " . Str::limit($post->title, 60) . ': ' . count($newer) . ' newer outlet(s)');

            if ($dry) {
                continue;
            }
            Cache::put($key, true, now()->addHours(2));
            if (! $newer) {
                continue;
            }

            // Re-fetch and independently assess sources. Text from feed results is
            // only a candidate; the existing fact checker can hold/reject the draft.
            $evidence = $pipeline->recordedEvidence($post, $newer);
            if (count($evidence) < 2 || ! \App\Services\SourcePacket::assess($evidence)['sufficient']) {
                $this->warn('  insufficient independent evidence — held');
                continue;
            }

            $revision = $pipeline->reviseWithSources(
                $post,
                $evidence,
                new AiClient,
                ['note' => 'Only revise for verified material developments after the original publication. '
                    . 'Do not turn an old event into breaking news. Preserve original claims and explicit source dates. '
                    . 'If there is no substantial new verified information, mark needs reporting.']
            );
            $this->line("  revision #{$revision->id}: {$revision->status} — live post unchanged");
        }

        $this->info("Checked {$checked} recent published article(s).");
        return self::SUCCESS;
    }
}
