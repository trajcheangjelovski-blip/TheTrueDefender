<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Post;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    /** Full sitemap: homepage, articles, categories, static pages. */
    public function index()
    {
        $xml = Cache::remember('sitemap.index', 1800, function () {
            $urls = [];
            $urls[] = $this->url(url('/'), now(), 'hourly', '1.0');

            foreach (['about', 'contact', 'newsroom', 'editorial-standards', 'corrections', 'privacy', 'terms'] as $slug) {
                $urls[] = $this->url(route('page', $slug), null, 'monthly', '0.4');
            }
            foreach (Category::where('is_active', true)->get() as $cat) {
                $urls[] = $this->url(route('category.show', $cat), null, 'hourly', '0.6');
            }
            // Topic hubs — only substantial ones (≥ MIN_STORIES) so we never
            // submit near-empty pages. Thin hubs carry noindex until they grow.
            $urls[] = $this->url(route('topics.index'), null, 'daily', '0.5');
            foreach (\App\Models\Tag::where('is_active', true)
                ->whereHas('posts', fn ($q) => $q->where('status', 'published')->where('published_at', '<=', now()), '>=', \App\Models\Tag::MIN_STORIES)
                ->get() as $tag) {
                $urls[] = $this->url(route('topic.show', $tag), null, 'daily', '0.6');
            }
            foreach (Post::published()->latest('published_at')->limit(5000)->get(['slug', 'updated_at']) as $p) {
                $urls[] = $this->url(url('/post/' . $p->slug), $p->updated_at, 'weekly', '0.7');
            }

            return $this->wrap(implode('', $urls), false);
        });

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }

    /** Google News sitemap: only articles from the last 48 hours. */
    public function news()
    {
        $xml = Cache::remember('sitemap.news', 600, function () {
            $name = config('app.name', 'TheTrueDefender');
            $items = '';
            foreach (Post::published()->where('published_at', '>=', now()->subHours(48))
                ->latest('published_at')->limit(1000)->get(['slug', 'title', 'published_at']) as $p) {
                $items .= '<url><loc>' . e(url('/post/' . $p->slug)) . '</loc>'
                    . '<news:news><news:publication>'
                    . '<news:name>' . e($name) . '</news:name>'
                    . '<news:language>en</news:language></news:publication>'
                    . '<news:publication_date>' . $p->published_at->toAtomString() . '</news:publication_date>'
                    . '<news:title>' . e($p->title) . '</news:title>'
                    . '</news:news></url>';
            }

            return $this->wrap($items, true);
        });

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }

    private function url(string $loc, $lastmod, string $freq, string $priority): string
    {
        return '<url><loc>' . e($loc) . '</loc>'
            . ($lastmod ? '<lastmod>' . $lastmod->toAtomString() . '</lastmod>' : '')
            . '<changefreq>' . $freq . '</changefreq><priority>' . $priority . '</priority></url>';
    }

    private function wrap(string $body, bool $news): string
    {
        $ns = $news ? ' xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"' : '';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . $ns . '>'
            . $body . '</urlset>';
    }
}
