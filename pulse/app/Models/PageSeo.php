<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PageSeo extends Model
{
    protected $table = 'page_seo';

    protected $fillable = [
        'key', 'label', 'path',
        'meta_title', 'meta_description', 'focus_keyword',
        'seo_score', 'seo_analysis', 'seo_analyzed_at',
        'gsc_position', 'gsc_clicks', 'gsc_impressions', 'gsc_ctr', 'gsc_synced_at',
    ];

    protected $casts = [
        'seo_analysis' => 'array',
        'seo_analyzed_at' => 'datetime',
        'gsc_position' => 'float',
        'gsc_ctr' => 'float',
        'gsc_synced_at' => 'datetime',
    ];

    /** The static pages we track SEO for. Seeded on demand. */
    public const PAGES = [
        ['key' => 'home',    'label' => 'Home',           'path' => '/'],
        ['key' => 'shop',    'label' => 'Shop',           'path' => '/shop'],
        ['key' => 'about',   'label' => 'About',          'path' => '/about'],
        ['key' => 'contact', 'label' => 'Contact',        'path' => '/contact'],
        ['key' => 'privacy', 'label' => 'Privacy Policy', 'path' => '/privacy'],
        ['key' => 'terms',   'label' => 'Terms',          'path' => '/terms'],
    ];

    /** Make sure a row exists for every tracked page. */
    public static function ensureSeeded(): void
    {
        foreach (self::PAGES as $page) {
            static::firstOrCreate(['key' => $page['key']], $page);
        }
    }

    /** Fetch (and lazily create) the SEO row for a page key. */
    public static function forKey(string $key): ?self
    {
        $def = collect(self::PAGES)->firstWhere('key', $key);
        if (! $def) {
            return null;
        }

        return static::firstOrCreate(['key' => $key], $def);
    }
}
