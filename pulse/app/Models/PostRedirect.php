<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A permanent redirect from a retired article slug to a surviving one.
 * Resolved by PostController + the /post/{post:slug} route's missing() handler,
 * so a retired URL 301s to its replacement whether the old row still exists
 * (unpublished) or has been deleted entirely.
 */
class PostRedirect extends Model
{
    protected $fillable = ['from_slug', 'to_slug', 'reason'];

    /** Follow a chain of redirects to the final live slug (guards against loops). */
    public static function resolve(string $fromSlug, int $maxHops = 5): ?string
    {
        $seen = [];
        $slug = $fromSlug;

        for ($i = 0; $i < $maxHops; $i++) {
            $next = static::where('from_slug', $slug)->value('to_slug');
            if ($next === null || $next === $slug || in_array($next, $seen, true)) {
                break;
            }
            $seen[] = $slug;
            $slug = $next;
        }

        return $slug === $fromSlug ? null : $slug;
    }
}
