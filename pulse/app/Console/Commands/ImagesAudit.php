<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\ImageAuditor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('images:audit {--fix : Replace branded images with AI-generated originals} {--source-only : Only posts that copied a source photo}')]
#[Description('Detect source-branded/watermarked post images with AI vision; optionally replace them with original AI images')]
class ImagesAudit extends Command
{
    public function handle(ImageAuditor $auditor): int
    {
        $fix = (bool) $this->option('fix');

        $query = Post::whereNotNull('featured_image');
        if ($this->option('source-only')) {
            $query->whereNotNull('source_url');
        }

        $branded = 0;
        $replaced = 0;

        $query->orderBy('id')->chunkById(25, function ($posts) use ($auditor, $fix, &$branded, &$replaced) {
            foreach ($posts as $post) {
                if ($fix) {
                    $r = $auditor->rebrandPost($post);
                    if (in_array($r['action'], ['replaced', 'branded-but-failed'], true)) {
                        $branded++;
                    }
                    if ($r['action'] === 'replaced') {
                        $replaced++;
                    }
                    $this->line("#{$post->id} {$r['action']} — " . str($post->title)->limit(45));
                } else {
                    $c = $auditor->inspect($post->featured_image);
                    if ($c['branded']) {
                        $branded++;
                    }
                    $this->line('#' . $post->id . ' ' . ($c['branded'] ? 'BRANDED' : 'clean') . " — {$c['detail']}");
                }
            }
        });

        $this->info($fix
            ? "Done. Replaced {$replaced} branded image(s)."
            : "Found {$branded} branded image(s). Re-run with --fix to replace them.");

        return self::SUCCESS;
    }
}
