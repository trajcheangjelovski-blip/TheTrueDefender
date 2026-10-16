<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class PostsStageReview extends Command
{
    protected $signature = 'posts:stage-review {file : Local JSON review package} {--store : Save private draft fields only}';

    protected $description = 'Validate an editorial review package; optionally store private draft fields, never publish or replace live content.';

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Review file is not readable.');
            return self::FAILURE;
        }
        try {
            $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->error('Invalid JSON review package.');
            return self::FAILURE;
        }
        if (! is_array($data)) {
            $this->error('Expected a JSON object.');
            return self::FAILURE;
        }
        $validator = Validator::make($data, [
            'slug' => 'required|string', 'draft_title' => 'required|string|max:255',
            'draft_excerpt' => 'required|string|max:500', 'draft_body' => 'required|string',
            'editorial_review_notes' => 'required|string', 'draft_sources' => 'required|array',
            'draft_sources.*.url' => ['required', 'url', 'regex:/^https?:\/\//i'],
            'draft_sources.*.title' => 'required|string', 'draft_sources.*.publisher' => 'required|string',
            'draft_sources.*.note' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());
            return self::FAILURE;
        }
        $post = Post::where('slug', $data['slug'])->first();
        if (! $post) {
            $this->error('Target article was not found. Nothing was created.');
            return self::FAILURE;
        }
        $this->info('Target: '.$post->slug.' ('.$post->status.')');
        if (! $this->option('store')) {
            $this->info('Valid review package. Dry run only; use --store to stage private fields.');
            return self::SUCCESS;
        }
        if (filled($post->draft_body)) {
            $this->error('An editorial review draft already exists. Review it before replacing it.');
            return self::FAILURE;
        }
        // Explicit allowlist: no title/body/status/slug/published_at or social observers.
        $post->forceFill(collect($validator->validated())->only([
            'draft_title', 'draft_excerpt', 'draft_body', 'draft_sources', 'editorial_review_notes',
        ])->all())->saveQuietly();
        $this->info('Private review draft staged. Published article and canonical URL are unchanged.');
        return self::SUCCESS;
    }
}
