<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Subscriber extends Model
{
    protected $fillable = ['email', 'name', 'status', 'source', 'unsubscribed_at'];

    protected $casts = [
        'unsubscribed_at' => 'datetime',
    ];

    /** Topics this subscriber follows — for future segmented topic digests. */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
