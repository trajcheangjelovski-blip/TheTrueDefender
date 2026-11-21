<?php

namespace App\Services\Social;

use App\Models\Post;

/** Compatibility entry for existing channel settings; website delivery uses TruthNewsroom. */
class TruthDriver extends AbstractSocialDriver
{
    public function key(): string
    {
        return 'truth';
    }

    public function label(): string
    {
        return 'Truth Social (coordinator review)';
    }

    public function configFields(): array
    {
        return [];
    }

    public function send(Post $post, array $config): array
    {
        return $this->fail('Select the article Truth profile and use the coordinator review queue. Direct unofficial posting is disabled.');
    }
}
