<?php

namespace App\Services;

use App\Models\Post;
use App\Support\ArticleLinks;

class LinkEnricher
{
    /** Retained for existing callers. Links are now chosen in the editor with context. */
    public function enrichPost(Post $post): bool
    {
        return false;
    }

    public function sanitize(string $html, array $candidates = []): string
    {
        return ArticleLinks::forDisplay($html);
    }
}
