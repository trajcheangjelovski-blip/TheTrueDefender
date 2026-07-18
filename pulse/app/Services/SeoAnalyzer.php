<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Scores the on-page SEO of a piece of content 0-100 and returns a
 * checklist + prioritized fixes. Uses OpenAI for the qualitative judgement,
 * grounded on deterministic local signals so the score is stable and cheap
 * to reason about. Falls back to a purely local heuristic when no API key.
 */
class SeoAnalyzer
{
    /**
     * @return array{
     *   score:int, grade:string, summary:string,
     *   checks:array<int,array{label:string,status:string,message:string}>,
     *   suggestions:array<int,string>,
     *   suggested:array{meta_title?:string,meta_description?:string,focus_keyword?:string},
     *   engine:string
     * }
     */
    public function analyze(
        string $title,
        ?string $body,
        ?string $metaTitle = null,
        ?string $metaDescription = null,
        ?string $focusKeyword = null,
        ?string $url = null,
    ): array {
        $signals = $this->signals($title, $body, $metaTitle, $metaDescription, $focusKeyword, $url);

        $key = Setting::get('openai_key', config('services.openai.key'));
        $reason = 'No OpenAI key configured — add one in AI & Ads Settings for AI-powered analysis.';

        if (filled($key)) {
            try {
                return $this->viaOpenAI($signals, $key);
            } catch (\Throwable $e) {
                Log::warning('SEO AI analysis failed, using local heuristic: ' . $e->getMessage());
                $reason = $this->failureReason($e);
            }
        }

        return $this->localScore($signals, $reason);
    }

    /** Human-readable cause when the AI call fails (shown in the score panel). */
    private function failureReason(\Throwable $e): string
    {
        $status = $e instanceof \Illuminate\Http\Client\RequestException
            ? $e->response?->status()
            : null;
        $code = $e instanceof \Illuminate\Http\Client\RequestException
            ? $e->response?->json('error.code')
            : null;

        return match (true) {
            $code === 'insufficient_quota' => 'Your OpenAI account is OUT OF CREDITS — add funds at platform.openai.com → Settings → Billing, then run again.',
            $status === 429 => 'OpenAI rate limit hit — wait a minute and run again.',
            $status === 401 => 'OpenAI rejected the API key (invalid/revoked) — check it in AI & Ads Settings.',
            default => 'OpenAI request failed (' . ($status ?? 'network error') . ') — try again shortly.',
        };
    }

    /** Deterministic on-page signals fed to the AI (and used by the fallback). */
    private function signals(
        string $title,
        ?string $body,
        ?string $metaTitle,
        ?string $metaDescription,
        ?string $focusKeyword,
        ?string $url,
    ): array {
        $html = (string) $body;
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        $words = $text === '' ? 0 : str_word_count($text);
        $kw = Str::lower(trim((string) $focusKeyword));
        $effectiveTitle = $metaTitle ?: $title;

        // First paragraph text (SEO weight on the intro).
        preg_match('/<p[^>]*>(.*?)<\/p>/is', $html, $m);
        $firstPara = Str::lower(strip_tags($m[1] ?? Str::words($text, 40, '')));

        $kwIn = fn (string $h) => $kw !== '' && str_contains(Str::lower($h), $kw);

        return [
            'title' => $title,
            'meta_title' => $metaTitle,
            'meta_title_length' => Str::length($effectiveTitle),
            'meta_description' => $metaDescription,
            'meta_description_length' => Str::length((string) $metaDescription),
            'focus_keyword' => $focusKeyword,
            'url' => $url,
            'word_count' => $words,
            'paragraph_count' => substr_count(Str::lower($html), '<p'),
            'h2_count' => substr_count(Str::lower($html), '<h2'),
            'h3_count' => substr_count(Str::lower($html), '<h3'),
            'image_count' => substr_count(Str::lower($html), '<img'),
            'images_missing_alt' => preg_match_all('/<img(?![^>]*\balt=)[^>]*>/i', $html),
            'link_count' => substr_count(Str::lower($html), '<a '),
            'keyword_in_title' => $kwIn($effectiveTitle),
            'keyword_in_meta_description' => $kwIn((string) $metaDescription),
            'keyword_in_first_paragraph' => $kw !== '' && str_contains($firstPara, $kw),
            'keyword_in_url' => $kw !== '' && $url && str_contains(Str::lower($url), Str::slug($kw)),
            'keyword_density_pct' => ($kw !== '' && $words > 0)
                ? round(substr_count(Str::lower($text), $kw) / max(1, $words) * 100, 2)
                : 0.0,
            'flesch_reading_ease' => $this->flesch($text, $words),
        ];
    }

    private function viaOpenAI(array $signals, string $key): array
    {
        // AI calls can outlive the web server's 30s limit — extend per call.
        set_time_limit(180);

        $site = config('app.name', 'TheTrueDefender');
        $custom = Setting::get('ai_instructions');

        $system = <<<SYS
        You are an expert SEO auditor for "{$site}". You are given a page's title,
        meta tags, focus keyword and a set of pre-computed on-page signals.
        Score the ON-PAGE SEO from 0 to 100 (100 = excellent) using SEO best practices:
        - Title / meta title 30-60 chars, compelling, contains the focus keyword.
        - Meta description 120-160 chars, contains the keyword, reads like a call to action.
        - Focus keyword present in title, first paragraph, URL, and used naturally (density ~0.5-2.5%, never stuffed).
        - Enough depth (300+ words for an article), scannable structure (H2/H3 subheadings).
        - Images have alt text; there is at least one link.
        - Readability (Flesch) is reasonable for a general audience.
        Return a grade ("good" >=80, "ok" 50-79, "poor" <50), a one-sentence summary,
        a checklist (each: label, status pass|warn|fail, short message), the most
        impactful fixes first, and improved suggested meta_title (<=60 chars),
        meta_description (<=160 chars) and focus_keyword.
        Be concrete and reference the actual content. Do not inflate the score.
        SYS;

        if (filled($custom)) {
            $system .= "\n\nBrand/editorial context (consider for tone of suggestions):\n" . $custom;
        }

        $user = "ON-PAGE SIGNALS (JSON):\n" . json_encode($signals, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $response = Http::withToken(trim($key))
            ->timeout(60)
            ->retry(2, 1000, \App\Support\OpenAiRetry::when(), throw: false)
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => Setting::get('openai_model', config('services.openai.model')),
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'seo_audit',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'score' => ['type' => 'integer'],
                                'grade' => ['type' => 'string', 'enum' => ['good', 'ok', 'poor']],
                                'summary' => ['type' => 'string'],
                                'checks' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'label' => ['type' => 'string'],
                                            'status' => ['type' => 'string', 'enum' => ['pass', 'warn', 'fail']],
                                            'message' => ['type' => 'string'],
                                        ],
                                        'required' => ['label', 'status', 'message'],
                                        'additionalProperties' => false,
                                    ],
                                ],
                                'suggestions' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'suggested' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'meta_title' => ['type' => 'string'],
                                        'meta_description' => ['type' => 'string'],
                                        'focus_keyword' => ['type' => 'string'],
                                    ],
                                    'required' => ['meta_title', 'meta_description', 'focus_keyword'],
                                    'additionalProperties' => false,
                                ],
                            ],
                            'required' => ['score', 'grade', 'summary', 'checks', 'suggestions', 'suggested'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            ])
            ->throw();

        $data = json_decode(data_get($response->json(), 'choices.0.message.content', ''), true);

        if (! is_array($data) || ! isset($data['score'])) {
            throw new \RuntimeException('AI returned unparseable SEO audit');
        }

        $data['score'] = max(0, min(100, (int) $data['score']));
        $data['engine'] = 'openai';

        return $data;
    }

    /** Purely local scoring — used when there is no OpenAI key or the call fails. */
    private function localScore(array $s, string $reason = ''): array
    {
        $checks = [];
        $add = function (string $label, bool $ok, ?bool $warn, string $msg) use (&$checks) {
            $checks[] = [
                'label' => $label,
                'status' => $ok ? 'pass' : ($warn ? 'warn' : 'fail'),
                'message' => $msg,
            ];
        };

        $titleLen = $s['meta_title_length'];
        $add('Title length', $titleLen >= 30 && $titleLen <= 60, $titleLen >= 20 && $titleLen <= 70,
            "{$titleLen} chars (aim 30–60).");

        $descLen = $s['meta_description_length'];
        $add('Meta description', $descLen >= 120 && $descLen <= 160, $descLen > 0,
            $descLen === 0 ? 'Missing — add one.' : "{$descLen} chars (aim 120–160).");

        $hasKw = filled($s['focus_keyword']);
        $add('Focus keyword set', $hasKw, false, $hasKw ? "“{$s['focus_keyword']}”" : 'No focus keyword.');
        $add('Keyword in title', ! $hasKw ? false : $s['keyword_in_title'], null,
            $s['keyword_in_title'] ? 'Present.' : 'Not found in title.');
        $add('Keyword in intro', ! $hasKw ? false : $s['keyword_in_first_paragraph'], null,
            $s['keyword_in_first_paragraph'] ? 'Present.' : 'Add it to the first paragraph.');
        $add('Keyword density', $hasKw && $s['keyword_density_pct'] >= 0.3 && $s['keyword_density_pct'] <= 2.5,
            $hasKw && $s['keyword_density_pct'] <= 3.5, "{$s['keyword_density_pct']}% (aim 0.5–2.5%).");

        $add('Content length', $s['word_count'] >= 300, $s['word_count'] >= 150,
            "{$s['word_count']} words (aim 300+).");
        $add('Subheadings', $s['h2_count'] >= 1, null,
            "{$s['h2_count']} H2 heading(s).");
        $add('Image alt text', $s['image_count'] === 0 || $s['images_missing_alt'] === 0, null,
            $s['images_missing_alt'] > 0 ? "{$s['images_missing_alt']} image(s) missing alt." : 'All images have alt.');
        $add('Links', $s['link_count'] >= 1, null, "{$s['link_count']} link(s).");
        $add('Readability', $s['flesch_reading_ease'] >= 50, $s['flesch_reading_ease'] >= 30,
            "Flesch {$s['flesch_reading_ease']} (higher = easier).");

        $pass = count(array_filter($checks, fn ($c) => $c['status'] === 'pass'));
        $score = (int) round($pass / max(1, count($checks)) * 100);
        $grade = $score >= 80 ? 'good' : ($score >= 50 ? 'ok' : 'poor');

        $suggestions = collect($checks)
            ->filter(fn ($c) => $c['status'] !== 'pass')
            ->map(fn ($c) => "{$c['label']}: {$c['message']}")
            ->values()->all();

        return [
            'score' => $score,
            'grade' => $grade,
            'summary' => "Local heuristic: {$pass}/" . count($checks) . ' checks passing. '
                . ($reason ?: 'AI analysis unavailable.'),
            'checks' => $checks,
            'suggestions' => $suggestions,
            'suggested' => [
                'meta_title' => Str::limit($s['meta_title'] ?: $s['title'], 60, ''),
                'meta_description' => (string) $s['meta_description'],
                'focus_keyword' => (string) $s['focus_keyword'],
            ],
            'engine' => 'local',
        ];
    }

    /** Flesch Reading Ease (approximate; 0–100, higher = easier). */
    private function flesch(string $text, int $words): float
    {
        if ($words === 0) {
            return 0.0;
        }
        $sentences = max(1, preg_match_all('/[.!?]+/', $text));
        $syllables = 0;
        foreach (preg_split('/\s+/', Str::lower($text)) as $w) {
            $w = preg_replace('/[^a-z]/', '', $w);
            if ($w === '') {
                continue;
            }
            $syllables += max(1, preg_match_all('/[aeiouy]+/', $w));
        }

        $score = 206.835 - 1.015 * ($words / $sentences) - 84.6 * ($syllables / $words);

        return round(max(0, min(100, $score)), 1);
    }
}
