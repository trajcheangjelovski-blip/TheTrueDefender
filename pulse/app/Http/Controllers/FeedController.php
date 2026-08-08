<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Support\Facades\Cache;

class FeedController extends Controller
{
    /**
     * Public RSS 2.0 feed of the latest articles — so third-party schedulers
     * (e.g. a Truth Social poster) can auto-publish each new story. Uses the
     * AI social caption as the description and the watermarked share image.
     */
    public function rss()
    {
        $xml = Cache::remember('feed.rss', 600, function () {
            $site = config('app.name', 'TheTrueDefender');
            $self = url('/rss');
            $home = url('/');

            $items = '';
            foreach (Post::published()->with('category')->latest('published_at')->limit(40)->get() as $p) {
                $url = url('/post/' . $p->slug);
                $desc = trim((string) ($p->social_text ?: $p->excerpt));
                $img = $p->shareImageUrl();

                $items .= '<item>'
                    . '<title>' . e($p->title) . '</title>'
                    . '<link>' . e($url) . '</link>'
                    . '<guid isPermaLink="true">' . e($url) . '</guid>'
                    . ($p->category ? '<category>' . e($p->category->name) . '</category>' : '')
                    . '<pubDate>' . $p->published_at->toRfc822String() . '</pubDate>'
                    . '<description><![CDATA[' . $desc . ']]></description>'
                    . ($img
                        ? '<enclosure url="' . e($img) . '" type="image/jpeg" length="0" />'
                          . '<media:content url="' . e($img) . '" medium="image" />'
                        : '')
                    . '</item>';
            }

            return '<?xml version="1.0" encoding="UTF-8"?>'
                . '<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/" xmlns:atom="http://www.w3.org/2005/Atom">'
                . '<channel>'
                . '<title>' . e($site) . '</title>'
                . '<link>' . e($home) . '</link>'
                . '<atom:link href="' . e($self) . '" rel="self" type="application/rss+xml" />'
                . '<description>' . e('Independent American news from ' . $site) . '</description>'
                . '<language>en-us</language>'
                . '<lastBuildDate>' . now()->toRfc822String() . '</lastBuildDate>'
                . $items
                . '</channel></rss>';
        });

        return response($xml, 200)->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }
}
