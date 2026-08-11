<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PushSubscription extends Model
{
    protected $fillable = ['endpoint', 'public_key', 'auth_token', 'content_encoding', 'topics_only'];

    protected $casts = ['topics_only' => 'boolean'];

    /** Topics this endpoint has chosen to follow (empty = follows nothing specific). */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
