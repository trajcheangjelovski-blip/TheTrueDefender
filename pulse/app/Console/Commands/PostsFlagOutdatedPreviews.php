<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\PostRevision;
use App\Support\EditorialPolicy;
use App\Support\EventLifecycle;
use Illuminate\Console\Command;

/**
 * Event lifecycle freshness for PUBLISHED stories: when the research package says a story previews, or reports as under way,
 * an event whose results are now due (a vote, an election, a ruling), flag it for an editor. Makes no AI calls, never edits or
 * republishes the article and never invents a result: it saves a "needs research" revision proposing a clearly dated update,
 * once per article. Safe to run repeatedly (hourly).
 */
class PostsFlagOutdatedPreviews extends Command
{
    protected $signature = 'posts:flag-outdated-previews {--dry : Show what would be flagged, change nothing} {--days=10 : Look at stories published within this many days}';

    protected $description = 'Flag published previews of events whose results are now due, for editorial review (no AI, no edits to the article).';

    public function handle(): int
    {
        $flagged = 0;
        $posts = Post::where('status', 'published')->whereNotNull('research_package')
            ->where('published_at', '>=', now()->subDays((int) $this->option('days')))->get();

        foreach ($posts as $post) {
            $package = (array) $post->research_package;
            if (! EventLifecycle::outdated($package)) {
                continue;
            }
            $already = PostRevision::where('post_id', $post->id)->get()->contains(fn ($r) => ($r->change_log['flag'] ?? null) === 'event_passed');
            if ($already) {
                continue;
            }
            $l = (array) ($package['lifecycle'] ?? []);
            $end = EventLifecycle::endAt($package);
            $this->line("#{$post->id} {$post->title} — event '" . ($l['event'] ?? '') . "' results were due " . ($end?->toDayDateTimeString() ?? '?'));
            if ($this->option('dry')) {
                continue;
            }
            PostRevision::create([
                'post_id' => $post->id,
                'status' => 'needs_research',
                'policy_version' => EditorialPolicy::version(),
                'title' => $post->title,
                'body' => (string) $post->body,
                'snapshot' => PostRevision::snapshotOf($post),
                'change_log' => [
                    'flag' => 'event_passed',
                    'summary' => 'The event this story previews or reports as under way (' . ($l['event'] ?? 'scheduled event') . ') is over: results were due ' . ($end?->toIso8601String() ?? 'unknown')
                        . '. The article may now be out of date.',
                    'proposal' => 'Verify the results with the official source (' . (($l['certification'] ?? '') ?: 'election authority or court') . '), then add a clearly dated "Update (Month Day, Year):" paragraph or rewrite as a results report. '
                        . 'Do not change the publication date and do not publish unverified results.',
                    'event' => $l['event'] ?? null,
                    'results_expected_at' => $l['results_expected_at'] ?? null,
                    'official_results_at' => $l['official_results_at'] ?? null,
                ],
            ]);
            $flagged++;
        }
        $this->info(($this->option('dry') ? '[DRY] ' : '') . "Outdated previews flagged for review: {$flagged}.");

        return self::SUCCESS;
    }
}
