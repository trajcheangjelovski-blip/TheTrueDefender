<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Create or update the site's named public author (default: Trajche Angjelovski,
 * Editor-in-Chief) and, optionally, assign the byline to existing posts.
 *
 * E-E-A-T: Google wants accountable, identifiable human authors for news. This
 * command is idempotent — safe to re-run — and makes NO OpenAI calls.
 *
 * Examples:
 *   php artisan authors:ensure                       # just create/update the author profile
 *   php artisan authors:ensure --assign-recent=60    # also byline the 60 newest published posts
 *   php artisan authors:ensure --assign-all          # byline EVERY post (use with care — see note)
 *   php artisan authors:ensure --email=you@admin.tld # link the author to your existing admin login
 *
 * Note on --assign-all: one human credited with thousands of news stories is
 * itself a weak E-E-A-T signal. Prefer assigning the curated set you keep after
 * the volume cut (posts:prune-thin), not the whole legacy catalog.
 */
class AuthorsEnsure extends Command
{
    protected $signature = 'authors:ensure
        {--name=Trajche Angjelovski : Display/byline name}
        {--slug= : URL slug (defaults to a slug of the name)}
        {--title=Editor-in-Chief : Role shown beside the byline}
        {--email=editor@thetruedefender.news : Account email to match or create}
        {--x= : Full X/Twitter profile URL}
        {--linkedin= : Full LinkedIn profile URL}
        {--public-email= : Contact email shown on the author page}
        {--bio= : Override the default bio text}
        {--credentials= : Override the default credentials text}
        {--as-default : Also set the brand "public_author" Setting to this name}
        {--assign-recent=0 : Byline the N most recent published posts to this author}
        {--assign-all : Byline ALL posts to this author (overrides --assign-recent)}';

    protected $description = 'Create/update the named public author (default Trajche Angjelovski) and optionally assign the byline. No AI calls.';

    public function handle(): int
    {
        $name = trim((string) $this->option('name'));
        $slug = Str::slug($this->option('slug') ?: $name);
        $email = trim((string) $this->option('email'));

        $bio = $this->option('bio') ?: sprintf(
            '%s is the founder and Editor-in-Chief of TheTrueDefender, an independent American news '
            . 'publication covering U.S. politics, national news, and world affairs. %s leads the '
            . 'newsroom\'s editorial standards, overseeing how every story is sourced, verified, and '
            . 'written before it is published.',
            $name,
            Str::of($name)->explode(' ')->first(),
        );

        $credentials = $this->option('credentials') ?: (
            'Founder and Editor-in-Chief of TheTrueDefender. Responsible for editorial oversight, '
            . 'sourcing standards, and corrections across all coverage.'
        );

        /** @var User $user */
        $user = User::firstOrNew(['email' => $email]);
        $isNew = ! $user->exists;

        $user->name = $name;
        $user->slug = $slug;
        $user->title = (string) $this->option('title');
        $user->bio = $bio;
        $user->credentials = $credentials;
        $user->x_url = $this->option('x') ?: $user->x_url;
        $user->linkedin_url = $this->option('linkedin') ?: $user->linkedin_url;
        $user->public_email = $this->option('public-email') ?: $user->public_email;
        $user->is_author = true;

        if ($isNew) {
            // A login-capable account the admin can later claim via a password
            // reset. Plaintext here — the model's 'hashed' cast hashes on save.
            $user->password = Str::random(40);
        }

        $user->save();

        $this->info(($isNew ? 'Created' : 'Updated') . " author: {$user->name} ({$user->email})");
        $this->line('  Author page: ' . route('author.show', $user->slug));

        if ($this->option('as-default')) {
            \App\Models\Setting::put('public_author', $name);
            $this->line("  Brand byline Setting 'public_author' set to: {$name}");
        }

        // ── Optional byline assignment ──────────────────────────────────────
        $assigned = 0;
        if ($this->option('assign-all')) {
            $assigned = Post::query()->update(['author_id' => $user->id]);
            $this->warn("  Bylined ALL {$assigned} posts to {$user->name}.");
        } elseif (($n = (int) $this->option('assign-recent')) > 0) {
            $ids = Post::query()->published()
                ->orderByDesc('published_at')
                ->limit($n)
                ->pluck('id');
            $assigned = Post::whereIn('id', $ids)->update(['author_id' => $user->id]);
            $this->info("  Bylined the {$assigned} most recent published posts to {$user->name}.");
        } else {
            $this->line('  (No posts re-bylined. Pass --assign-recent=N or --assign-all to assign.)');
        }

        $this->newLine();
        $this->info('Done. Clear caches if needed: php artisan view:clear && php artisan route:clear');

        return self::SUCCESS;
    }
}
