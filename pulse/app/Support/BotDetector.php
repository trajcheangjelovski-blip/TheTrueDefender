<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Keeps crawlers, scrapers, preview fetchers and monitors out of the analytics
 * counters (views, impressions, clicks, active-visitor heartbeats). Detection is
 * User-Agent based: we only count a hit as human when the UA looks like a real
 * browser and matches none of the known bot markers. A missing/empty UA is
 * treated as a bot — real browsers always send one.
 */
class BotDetector
{
    /**
     * Substrings that mark a non-human client. Matched case-insensitively against
     * the User-Agent. Covers search engines, social/link previewers, SEO tools,
     * headless browsers, HTTP libraries and uptime monitors.
     */
    private const MARKERS = [
        // Generic self-identifiers — catch the long tail of crawlers.
        'bot', 'crawl', 'spider', 'slurp', 'scrape', 'fetch', 'archiver', 'index',
        // Search engines & big crawlers.
        'googlebot', 'google-inspectiontool', 'storebot-google', 'bingbot', 'adidxbot',
        'yandex', 'baidu', 'duckduck', 'sogou', 'exabot', 'ia_archiver', 'applebot',
        'petalbot', 'bytespider', 'amazonbot', 'seznambot',
        // AI / LLM crawlers.
        'gptbot', 'oai-searchbot', 'chatgpt', 'ccbot', 'claudebot', 'claude-web',
        'anthropic', 'perplexitybot', 'google-extended', 'meta-externalagent',
        'diffbot', 'omgili', 'img2dataset', 'timpibot', 'youbot',
        // Social / messaging link previewers.
        'facebookexternalhit', 'facebot', 'twitterbot', 'linkedinbot', 'slackbot',
        'telegrambot', 'whatsapp', 'discordbot', 'pinterest', 'redditbot',
        'skypeuripreview', 'embedly', 'quora link preview', 'vkshare', 'w3c_validator',
        // SEO / marketing / scraping suites.
        'ahrefs', 'semrush', 'mj12bot', 'dotbot', 'rogerbot', 'screaming frog',
        'blexbot', 'serpstat', 'dataforseo', 'seokicks', 'sistrix', 'barkrowler',
        // Headless browsers & automation.
        'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'selenium',
        'electron', 'cypress', 'lighthouse', 'pagespeed', 'chrome-lighthouse',
        // HTTP libraries & CLI tools.
        'python-requests', 'python-httpx', 'aiohttp', 'curl', 'wget', 'libwww-perl',
        'java/', 'okhttp', 'go-http-client', 'node-fetch', 'axios', 'guzzlehttp',
        'httpclient', 'apache-httpclient', 'postmanruntime', 'insomnia', 'httrack',
        // Uptime / monitoring.
        'uptimerobot', 'pingdom', 'statuscake', 'site24x7', 'newrelicpinger',
        'datadog', 'gtmetrix', 'better uptime', 'headless',
        // Feeds.
        'feedfetcher', 'feedly', 'feedburner', 'rss',
    ];

    /** True when the request's User-Agent looks like a bot (or is absent). */
    public static function isBot(Request $request): bool
    {
        return self::isBotUserAgent((string) $request->userAgent());
    }

    /** Same check against a raw User-Agent string. */
    public static function isBotUserAgent(string $userAgent): bool
    {
        $ua = strtolower(trim($userAgent));

        // No UA at all → not a real browser. Count as a bot.
        if ($ua === '') {
            return true;
        }

        foreach (self::MARKERS as $marker) {
            if (str_contains($ua, $marker)) {
                return true;
            }
        }

        return false;
    }
}
