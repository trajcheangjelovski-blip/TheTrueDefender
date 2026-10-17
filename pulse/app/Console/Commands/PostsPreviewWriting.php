<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\Setting;
use App\Services\AiClient;
use App\Services\EditorialPipeline;
use App\Services\FactChecker;
use App\Services\Rewriter;
use App\Support\EditorialPolicy;
use App\Support\PublishGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Bounded editorial experiments, completely separate from publication. */
class PostsPreviewWriting extends Command
{
    protected $signature = 'posts:preview-writing {post : Existing article ID}
        {--check : Make one additional independent fact-check call}
        {--feedback= : Specific editorial feedback for this preview only}';

    protected $description = 'Private writing preview: reuse recorded research, one writing call, optional fact-check, no automatic retries, SEO, images or publication.';

    public function handle(EditorialPipeline $pipeline, Rewriter $writer, FactChecker $checker): int
    {
        $post = Post::findOrFail($this->argument('post'));
        $model = Setting::get('openai_model', config('services.openai.model'));
        $checkModel = Setting::get('fact_check_model') ?: $model;
        $this->info("Preview writer: {$model}; fact-checker: {$checkModel}. No article will be saved or published.");

        // No new search: recordedEvidence reuses the normal page cache. Three
        // existing sources keep trials comparable and the input bounded.
        $sources = array_slice($pipeline->recordedEvidence($post), 0, 3);
        if (count($sources) < 2) {
            $this->error('Fewer than two recorded sources could be retrieved. Preview skipped.');
            return self::FAILURE;
        }
        $ai = new AiClient(maxCalls: $this->option('check') ? 2 : 1, maxCompletionTokens: 3000, maxInputBytes: 100000);
        $draft = $writer->writeArticle($ai, 'new', [
            'title' => $post->title, 'category' => $post->category?->name ?? 'News',
            'sources' => $sources,
            'feedback' => 'Apply the current editorial policy to this private style trial. Preserve verified facts and attribution. '
                . Str::limit((string) $this->option('feedback'), 1500, ''),
        ]);
        $check = $draft && $this->option('check')
            ? $checker->check($ai, $draft['headline'], $draft['body'], $sources, $post->published_at?->toIso8601String())
            : null;
        $probe = $draft ? new Post([
            'title' => $draft['headline'], 'body' => $draft['body'], 'category_id' => $post->category_id,
            'story_type' => $draft['story_type'], 'sources' => $sources,
            'fact_check' => $check, 'review_status' => $check['verdict'] ?? 'needs_research',
        ]) : null;
        if ($probe) {
            $probe->id = $post->id; // don't compare the preview with its own source article
        }
        $report = [
            'preview_only' => true, 'based_on_post' => $post->id, 'created_at' => now()->toIso8601String(),
            'policy_version' => EditorialPolicy::version(), 'model' => $model, 'fact_check_model' => $checkModel,
            'feedback' => $this->option('feedback'), 'draft' => $draft, 'fact_check' => $check,
            'quality_findings' => $probe ? PublishGate::originality($probe) : ['empty' => 'Writer returned no usable preview.'],
            'publish_gate_failures' => $probe ? PublishGate::failures($probe) : ['empty' => 'Writer returned no usable preview.'],
            'usage' => $ai->usage(), 'sources' => $sources,
        ];
        $path = 'editorial-previews/' . now()->format('Ymd-His') . '-' . Str::uuid() . '.json';
        if (! Storage::disk('local')->put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) {
            $this->error('Private preview could not be saved.');
            return self::FAILURE;
        }
        $this->info('Private preview: ' . Storage::disk('local')->path($path));
        $usage = $ai->usage();
        $this->info("API usage: {$usage['calls']} calls, {$usage['prompt_tokens']} input tokens, {$usage['completion_tokens']} output tokens.");
        $this->info('Fact-check: ' . ($check['verdict'] ?? 'not run') . '; quality findings: ' . count($report['quality_findings']));
        $this->info('Automatic publish gate: ' . ($report['publish_gate_failures'] ? count($report['publish_gate_failures']) . ' blocker(s)' : 'passed'));

        return $draft ? self::SUCCESS : self::FAILURE;
    }
}
