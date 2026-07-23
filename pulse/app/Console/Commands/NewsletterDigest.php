<?php

namespace App\Console\Commands;

use App\Mail\DigestMail;
use App\Models\Category;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Subscriber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class NewsletterDigest extends Command
{
    protected $signature = 'newsletter:digest {--force : Send even if disabled/no new posts}';

    protected $description = 'Email subscribers a digest of the top recent stories.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        if (! $force && ! filter_var(Setting::get('digest_enabled', false), FILTER_VALIDATE_BOOL)) {
            $this->info('Digest disabled — skipping.');
            return self::SUCCESS;
        }
        if (blank(Setting::get('mail_host'))) {
            $this->warn('No SMTP host configured (AI & Ads Settings → Email). Nothing sent.');
            return self::SUCCESS;
        }

        // Top stories since the last digest (fallback: last 24h), most important first.
        $since = Setting::get('last_digest_at');
        $cutoff = $since ? \Illuminate\Support\Carbon::parse($since) : now()->subDay();
        $opinionId = Category::where('slug', 'opinion')->value('id');

        $posts = Post::published()->with('category')
            ->where('published_at', '>=', $cutoff)
            ->when($opinionId, fn ($q) => $q->where('category_id', '!=', $opinionId))
            ->orderByDesc('is_featured')->orderByDesc('is_breaking')->latest('published_at')
            ->take(6)->get();

        if ($posts->isEmpty() && ! $force) {
            $this->info('No new stories since the last digest — skipping.');
            return self::SUCCESS;
        }
        if ($posts->isEmpty()) {
            $posts = Post::published()->with('category')->latest('published_at')->take(6)->get();
        }

        $subscribers = Subscriber::where('status', 'subscribed')->get();
        $sent = 0;

        foreach ($subscribers as $sub) {
            try {
                $unsub = URL::signedRoute('newsletter.unsubscribe', ['subscriber' => $sub->id]);
                Mail::to($sub->email)->send(new DigestMail($posts, $unsub));
                $sent++;
            } catch (\Throwable $e) {
                Log::warning("Digest send failed for {$sub->email}: " . $e->getMessage());
            }
        }

        Setting::put('last_digest_at', now()->toDateTimeString());
        $this->info("Digest sent to {$sent} subscriber(s), featuring {$posts->count()} stories.");

        return self::SUCCESS;
    }
}
