<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AdPlacement extends Model
{
    protected $fillable = ['key', 'name', 'description', 'is_enabled', 'format', 'ad_slot', 'custom_html'];

    protected $casts = ['is_enabled' => 'boolean'];

    /** Per-request memo (one query, reused for all placements on a page). */
    private static ?Collection $memo = null;

    public static function byKey(string $key): ?self
    {
        self::$memo ??= static::query()->get()->keyBy('key');

        return self::$memo->get($key);
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::$memo = null);
        static::deleted(fn () => self::$memo = null);
    }
}
