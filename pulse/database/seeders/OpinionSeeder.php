<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class OpinionSeeder extends Seeder
{
    /**
     * Seed a batch of Opinion-forum discussion posts. Published immediately with
     * published_at = now() so they appear on the homepage and Opinion page right
     * away. Idempotent by slug. Mirrors how OpinionsGenerate creates posts
     * (💬 icon, comments on, push/social fan-out suppressed for forum topics).
     */
    public function run(): void
    {
        $opinion = Category::where('slug', 'opinion')->first();
        if (! $opinion) {
            $this->command?->error('Opinion category not found — run the main seeder first.');

            return;
        }

        $author = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first() ?? User::first();

        $pieces = [
            [
                'title' => 'Should Securing the Border Be America’s First Priority?',
                'excerpt' => 'A secure border is either the foundation of every other reform — or a distraction from them. Which is it?',
                'body' => '<p>Every debate about immigration eventually circles back to one question: can a country set its own rules if it cannot decide who crosses its line on a map? Supporters of a border-first approach argue that sovereignty, public safety, and fair wages all depend on it. Critics counter that the economy and basic decency depend just as much on the people already here.</p>'
                    . '<p>What often gets lost is that both can be true at once. A nation can insist on order at the border and still treat the human beings crossing it with dignity. The hard part is agreeing on the sequence — what comes first, and who pays the price while we argue about it.</p>'
                    . '<p>Where do you land? Is the border the first domino that makes every other fix possible, or a slogan that crowds out harder conversations? Share your view below.</p>',
            ],
            [
                'title' => 'Is the Economy Really the Only Issue That Decides Elections?',
                'excerpt' => '“It’s the economy, stupid” has ruled politics for decades. Does it still hold — or have the rules changed?',
                'body' => '<p>For a generation, the shortest path to predicting an election was to look at wages, prices, and jobs. When people feel richer, incumbents win; when the grocery bill stings, they lose. It is a simple rule, and it has been right more often than not.</p>'
                    . '<p>But something feels different lately. Voters tell pollsters the economy is their top concern, then cast ballots over questions of trust, culture, and identity. Maybe the economy still decides everything — or maybe it has become the language we use to talk about deeper anxieties about the country’s direction.</p>'
                    . '<p>What moves you when you step into the voting booth: your wallet, your values, or the sense that no one in charge is listening? Tell us below.</p>',
            ],
            [
                'title' => 'Have We Forgotten How to Disagree Without Enemies?',
                'excerpt' => 'Free speech means little if every disagreement turns a neighbor into a villain. Can we still argue in good faith?',
                'body' => '<p>Protecting our freedoms is easy to cheer for in the abstract. The test comes when the person exercising that freedom is saying something we find wrong, or even offensive. A healthy republic depends on citizens who can hear that and answer with a better argument instead of a demand for silence.</p>'
                    . '<p>Somewhere along the way, we started treating every political opponent as an existential threat. Outrage travels faster than persuasion, and platforms reward the loudest, not the wisest. The cost is a public square where fewer people risk saying what they actually think.</p>'
                    . '<p>Do you think Americans can still disagree strongly and part as neighbors — or has that door closed? Make your case below.</p>',
            ],
        ];

        $made = 0;
        foreach ($pieces as $p) {
            $slug = $this->uniqueSlug($p['title']);

            // Skip if an opinion with this title already exists (idempotent).
            if (Post::where('category_id', $opinion->id)->where('title', $p['title'])->exists()) {
                continue;
            }

            Post::create([
                'title' => $p['title'],
                'slug' => $slug,
                'excerpt' => $p['excerpt'],
                'body' => $p['body'],
                'category_id' => $opinion->id,
                'author_id' => $author?->id,
                'image_icon' => '💬',
                'status' => 'published',
                'published_at' => now(),
                'allow_comments' => true,
                // Forum topics: don't fan out to push/social.
                'push_notified_at' => now(),
                'social_posted_at' => now(),
            ]);
            $made++;
        }

        $this->command?->info("Seeded {$made} opinion post(s).");
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'opinion';
        $slug = $base;
        $i = 2;
        while (Post::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
