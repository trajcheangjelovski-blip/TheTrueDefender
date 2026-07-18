<?php

namespace App\Services\Social;

use App\Models\Post;

interface SocialDriver
{
    /** Stable driver key stored on the channel (e.g. "telegram"). */
    public function key(): string;

    /** Human label for the admin UI. */
    public function label(): string;

    /** Config fields this driver needs: ['field_key' => 'Label', ...]. */
    public function configFields(): array;

    /**
     * Publish a post to this channel.
     *
     * @param array<string,mixed> $config
     * @return array{ok:bool,id:?string,url:?string,error:?string}
     */
    public function send(Post $post, array $config): array;
}
