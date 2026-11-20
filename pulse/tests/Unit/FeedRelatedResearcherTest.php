<?php

namespace Tests\Unit;

use App\Services\FeedRelatedResearcher;
use PHPUnit\Framework\TestCase;

class FeedRelatedResearcherTest extends TestCase
{
    public function test_rejects_unrelated_news(): void
    {
        self::assertFalse(FeedRelatedResearcher::headlineMatch(
            'Congress passes emergency spending bill after overnight debate',
            'Severe storms damage homes across southern Florida'
        ));
    }

    public function test_finds_headline_variants(): void
    {
        self::assertTrue(FeedRelatedResearcher::headlineMatch(
            'Senate committee approves new aviation safety measure',
            'New aviation safety measure approved by Senate committee'
        ));
    }

    public function test_generic_one_word_match_does_not_qualify(): void
    {
        self::assertFalse(FeedRelatedResearcher::headlineMatch(
            'Trump addresses Congress on spending proposal',
            'Trump meets regional leaders to discuss trade'
        ));
    }
}
