<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Visit extends Model
{
    public $timestamps = false;

    protected $fillable = ['visitor_id', 'path', 'device', 'country', 'user_agent', 'last_seen_at', 'created_at'];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /** Visitors seen within the last $minutes (default 2 — one missed heartbeat of slack). */
    public function scopeActive(Builder $query, int $minutes = 2): Builder
    {
        return $query->where('last_seen_at', '>=', now()->subMinutes($minutes));
    }

    /**
     * Only visitors who actually stayed: they sent a second heartbeat
     * (last_seen_at later than created_at), so the tab was open past the first
     * ping. engage.js pings on load then every 60s, so this filters out the
     * one-and-done drive-bys — overwhelmingly bots that load the page, run the
     * JS once and leave. Pairs with the write-time BotDetector.
     */
    public function scopeEngaged(Builder $query): Builder
    {
        return $query->whereColumn('last_seen_at', '>', 'created_at');
    }
}
