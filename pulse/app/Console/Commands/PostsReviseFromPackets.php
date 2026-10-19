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
        {--with-research : Run the research desk (gpt-5.4) on the packets first: timeline, figures, phases, official claims; the writer and the fact-checker receive the package}
        {--reuse-research= : JSON file of a research package saved by an earlier --with-research run (saves the research call)}
        {--allow-single-origin : Editor decision: proceed when all packets rest on ONE original report (copies and aggregators do not count as confirmation); the article must credit that original throughout}
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
            $originals = array_filter($sources, fn ($x) => ($x['relation'] ?? null) === 'original' && str_word_count((string) ($x['text'] ?? '')) >= 80);
            if (! $this->option('allow-single-origin') || ! $originals) {
                $this->error('Needs reporting before any paid call: ' . implode(' ', $assessment['reasons']));

                return self::FAILURE;
            }
            $this->warn('Proceeding on an editor override: ' . implode(' ', $assessment['reasons']) . ' The article must credit the original reporting and claim no independent confirmation.');
        }

        $ai = new AiClient(maxCalls: (int) $this->option('max-calls'), maxCompletionTokens: $this->option('with-research') ? 32000 : 4000, maxInputBytes: $this->option('with-research') ? 200000 : 90000, maxCostUsd: (float) $this->option('max-usd'));
        $package = null;
        if ($this->option('reuse-research')) {
            $package = json_decode((string) @file_get_contents((string) $this->option('reuse-research')), true);
            if (! is_array($package) || empty($package['event'])) {
                $this->error('Research package file unreadable.');

                return self::FAILURE;
            }
            $this->info('Reusing the saved research package (no research call).');
        } elseif ($this->option('with-research')) {
            $why = null;
            $package = app(\App\Services\ResearchAnalyst::class)->analyze($ai, $post, $sources, $why);
            if (! $package) {
                $this->error('Research stage failed: ' . $why);
                $this->line('Usage before the failure: estimated $' . ($ai->usage()['estimated_usd'] ?? 'n/a'));

                return self::FAILURE;
            }
            $this->info('Research package: ' . count((array) ($package['figures'] ?? [])) . ' figures, ' . count((array) ($package['phases'] ?? [])) . ' phases, '
                . count((array) ($package['timeline'] ?? [])) . ' timeline entries, ' . count((array) ($package['claims'] ?? [])) . ' official claims.');
            file_put_contents(storage_path('app/private/research-package-post' . $post->id . '.json'), json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $rev = $pipeline->reviseWithSources($post, $sources, $ai, ['research' => $package, 'note' => (string) $this->option('note'), 'opinion' => (bool) $this->option('opinion'), 'skip_original_check' => true]);
        $u = $ai->usage();
        $this->info("Revision #{$rev->id} status: {$rev->status}; fact-check: " . ($rev->fact_check['verdict'] ?? 'n/a') . '; critical: ' . ($rev->fact_check['critical'] ?? 0));
        foreach ((array) ($rev->fact_check['issues'] ?? []) as $i) {
            $this->line("  [{$i['severity']}] {$i['claim']} - {$i['problem']}");
        }
        $this->info("API usage: {$u['calls']} calls, {$u['prompt_tokens']} in, {$u['completion_tokens']} out, estimated \$" . ($u['estimated_usd'] ?? 'n/a')
            . ' (' . collect($u['by_model'] ?? [])->map(fn ($m, $name) => $name . ' $' . $m['usd'])->implode(', ') . ')');

        return self::SUCCESS;
    }
}
