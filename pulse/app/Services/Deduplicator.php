<?php

namespace App\Services;

use App\Models\IngestedItem;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cross-feed duplicate detection: decides whether an incoming feed item is
 * the same story as one we already ingested (from ANY feed), so the pipeline
 * can skip it instead of publishing the same news twice.
 *
 * Primary signal: OpenAI embeddings of the source title+summary, compared by
 * cosine similarity against recent items. Fallback (no API key / API failure):
 * fuzzy title similarity. Both windows and thresholds are tunable via settings.
 */
class Deduplicator
{
    /** How many days back to compare against. */
    private const WINDOW_DAYS = 7;

    /** Max recent items to compare against (keeps the loop cheap). */
    private const MAX_CANDIDATES = 500;

    /**
     * Returns the IngestedItem this item duplicates, or null if it's fresh news.
     *
     * @param array{title:string,summary:string} $item
     */
    public function findDuplicate(array $item, ?array &$embedding = null): ?IngestedItem
    {
        $candidates = IngestedItem::query()
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->whereIn('status', ['processed', 'duplicate'])
            ->latest()
            ->take(self::MAX_CANDIDATES)
            ->get(['id', 'title', 'embedding', 'post_id']);

        $embedding = $this->embed($this->text($item));

        if ($candidates->isEmpty()) {
            return null;
        }

        // Hybrid comparison, per candidate:
        //  - semantic (cosine of embeddings) when both sides have a vector
        //  - fuzzy title similarity otherwise (candidates ingested before
        //    embeddings existed, or runs without an API key)
        $threshold = (float) Setting::get('dedup_threshold', 0.85);
        $title = Str::lower(trim($item['title']));

        foreach ($candidates as $candidate) {
            $other = $candidate->embedding;

            if ($embedding !== null && is_array($other) && count($other) === count($embedding)) {
                $score = $this->cosine($embedding, $other);
                if ($score >= $threshold) {
                    Log::info('Dedup: semantic match ' . round($score, 3) . " vs item {$candidate->id} ({$candidate->title})");
                    return $candidate;
                }
            } else {
                similar_text($title, Str::lower(trim($candidate->title)), $pct);
                if ($pct >= 78) {
                    Log::info('Dedup: title match ' . round($pct) . "% vs item {$candidate->id} ({$candidate->title})");
                    return $candidate;
                }
            }
        }

        return null;
    }

    /** The text we embed: title carries the most signal, summary adds context. */
    private function text(array $item): string
    {
        return Str::limit(trim($item['title'] . "\n" . strip_tags($item['summary'] ?? '')), 1500, '');
    }

    /** Embed via OpenAI; null when no key or on failure (callers fall back). */
    public function embed(string $text): ?array
    {
        $key = Setting::get('openai_key', config('services.openai.key'));
        if (blank($key) || blank($text)) {
            return null;
        }

        try {
            set_time_limit(180); // don't let a slow call hit the web server's 30s cap

            $response = Http::withToken(trim($key))
                ->timeout(30)
                ->retry(2, 1000, \App\Support\OpenAiRetry::when(), throw: false)
                ->acceptJson()
                ->post('https://api.openai.com/v1/embeddings', [
                    'model' => 'text-embedding-3-small',
                    'input' => $text,
                ])
                ->throw();

            $vector = data_get($response->json(), 'data.0.embedding');

            return is_array($vector) ? $vector : null;
        } catch (\Throwable $e) {
            Log::warning('Dedup embedding failed (falling back to title match): ' . $e->getMessage());

            return null;
        }
    }

    private function cosine(array $a, array $b): float
    {
        $dot = $na = $nb = 0.0;
        foreach ($a as $i => $v) {
            $dot += $v * $b[$i];
            $na += $v * $v;
            $nb += $b[$i] * $b[$i];
        }

        return ($na && $nb) ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }
}
