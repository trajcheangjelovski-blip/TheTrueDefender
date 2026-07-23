<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use App\Observers\CommentObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([CommentObserver::class])]
class Comment extends Model
{
    protected $fillable = [
        'post_id', 'parent_id', 'name', 'surname', 'body', 'email', 'phone', 'status', 'ip_hash',
        'ai_reason', 'moderated_at', 'created_at', 'reply_notified_at',
    ];

    protected $casts = [
        'moderated_at' => 'datetime',
        'reply_notified_at' => 'datetime',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    /** Approved replies to this comment, oldest first. */
    public function approvedReplies(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id')->approved()->oldest();
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    /** Public display name — first name + surname only. */
    public function getDisplayNameAttribute(): string
    {
        return trim($this->name . ' ' . $this->surname);
    }
}
