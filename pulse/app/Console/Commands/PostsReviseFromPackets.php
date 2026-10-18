<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\AiClient;
use App\Services\ArticleFetcher;
use App\Services\EditorialPipeline;
use App\Services\SourcePacket;
use App\Services\WebResearcher;
use Illuminate\Console\Command;

/**
 * The full revision pipeline (original check, write, fact-check, up to two fix passes) on editor-chosen
 * sources. Saves a revision draft only: the live article changes when `posts:revision apply` is run.
 */
class PostsReviseFromPackets extends Command
{
    protected $signature = 'posts:revise-from-packets {post : Published article ID}
        {--packets= : JSON file [{url, publisher?, title?, published_at?, citation?}]}
        {--note= : Editor note for the writer}
        {--opinion : Labeled opinion column}
        {--max-calls=8 : Hard limit on paid model requests}
        {--max-usd=0.30 : Hard USD ceiling (worst case checked before every request)}';

    protected $description = 'Revise a published article from editor-chosen source packets (saved as a revision draft, never published here).';

    public function handle(EditorialPipeline $pipeline, ArticleFetcher $fetcher, WebResearcher $researcher): int
    {
        $post = Post::findOrFail($this->argument('post'));
        $entries = json_decode((string) @file_get_contents((string) $this->option('packets')), true);
        if (! is_array($entries) || ! $entries) {
            $this->error('Packet file unreadable.');

            return self::FAILURE;
        }
        $raw = [];
        foreach ($entries as $e) {
            if (filled($e['citation'] ?? null)) {
                $raw[] = ['name' => $e['publisher'] ?? 'reference', 'url' => $e['url'], 'title' => $e['title'] ?? null, 'published_at' => $e['published_at'] ?? null,
                    'accessed_at' => now()->toIso8601String(), 'kind' => 'citation_only', 'text' => $e['citation']];

                continue;
            }
            $page = $fetcher->extract($e['url']);
            if (str_word_count((string) ($page['text'] ?? '')) < 60) {
                $this->warn('Unfetchable, skipped: ' . $e['url']);

                continue;
            }
            $raw[] = ['text' => $page['text']] + $researcher->sourceRecord($e['url'], $page, $e['publisher'] ?? null);
        }
        $sources = SourcePacket::prepare($raw, $post->title . ' ' . (string) $this->option('note'));
        $assessment = SourcePacket::assess($sources, (bool) $this->option('opinion'));
        if (! $assessment['sufficient']) {
            $this->error('Needs reporting before any paid call: ' . implode(' ', $assessment['reasons']));

            return self::FAILURE;
        }

        $ai = new AiClient(maxCalls: (int) $this->option('max-calls'), maxCompletionTokens: 4000, maxInputBytes: 90000, maxCostUsd: (float) $this->option('max-usd'));
        $rev = $pipeline->reviseWithSources($post, $sources, $ai, ['note' => (string) $this->option('note'), 'opinion' => (bool) $this->option('opinion'), 'skip_original_check' => true]);
        $u = $ai->usage();
        $this->info("Revision #{$rev->id} status: {$rev->status}; fact-check: " . ($rev->fact_check['verdict'] ?? 'n/a') . '; critical: ' . ($rev->fact_check['critical'] ?? 0));
        foreach ((array) ($rev->fact_check['issues'] ?? []) as $i) {
            $this->line("  [{$i['severity']}] {$i['claim']} - {$i['problem']}");
        }
        $this->info("API usage: {$u['calls']} calls, {$u['prompt_tokens']} in, {$u['completion_tokens']} out, \$" . ($u['test_cost_usd'] ?? 0));

        return self::SUCCESS;
    }
}
