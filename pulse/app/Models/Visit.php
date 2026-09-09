<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Visit extends Model
{
    public $timestamps = false;

    protected $fillable = ['visitor_id', 'path', 'device', 'country', 'last_seen_at', 'created_at'];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /** Visitors seen within the last $minutes (default 2 — one missed heartbeat of slack). */
    public function scopeActive(Builder $query, int $minutes = 2): Builder
    {
        return $query->where('last_seen_at', '>=', now()->subMinutes($minutes));
    }
}
