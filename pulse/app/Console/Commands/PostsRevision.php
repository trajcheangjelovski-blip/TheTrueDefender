<?php

namespace App\Console\Commands;

use App\Models\PostRevision;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Inspect and act on revision drafts from the command line (the admin's
 * Revisions screen does the same). Applying publishes a revision to the live
 * article — an editorial decision: --editor records WHO approved it, and it
 * should only be run by a person who has actually reviewed the revision.
 *
 *   php artisan posts:revision list [--status=pending_review]
 *   php artisan posts:revision show 12
 *   php artisan posts:revision apply 12 --editor=you@example.com
 *   php artisan posts:revision reject 12 --editor=you@example.com
 *   php artisan posts:revision rollback 12
 */
class PostsRevision extends Command
{
    protected $signature = 'posts:revision
        {action : list | show | apply | reject | rollback}
        {id? : Revision ID}
        {--status= : Filter the list by status}
        {--editor= : Email or ID of the editor approving/rejecting}
        {--confirm-correction=* : Number (from `show`) of a proposed correction you verified; repeatable. None = no correction note}';

    protected $description = 'List, inspect, apply, reject or roll back article revision drafts.';

    public function handle(): int
    {
        $action = $this->argument('action');

        if ($action === 'list') {
            $rows = PostRevision::with('post:id,title')->latest('id')
                ->when($this->option('status'), fn ($q, $s) => $q->where('status', $s))
                ->limit(200)->get()
                ->map(fn ($r) => [$r->id, $r->post_id, $r->status, (int) ($r->fact_check['critical'] ?? 0),
                    count((array) ($r->change_log['corrections'] ?? [])), $r->policy_version, Str::limit($r->post?->title, 50)]);
            $this->table(['Rev', 'Post', 'Status', 'Critical', 'Corrections', 'Policy', 'Article'], $rows);

            return self::SUCCESS;
        }

        $rev = PostRevision::with('post')->find($this->argument('id'));
        if (! $rev) {
            $this->error('Revision not found.');

            return self::FAILURE;
        }

        $editorId = null;
        if (filled($e = $this->option('editor'))) {
            $editorId = User::where('email', $e)->orWhere('id', (int) $e)->value('id');
            if (! $editorId) {
                $this->error("No user matches --editor={$e}.");

                return self::FAILURE;
            }
        }

        try {
            match ($action) {
                'show' => $this->show($rev),
                'apply' => $rev->apply($editorId, array_map(fn ($n) => (int) $n - 1, (array) $this->option('confirm-correction'))),
                'reject' => $rev->reject($editorId),
                'rollback' => $rev->rollback(),
                default => throw new \InvalidArgumentException("Unknown action '{$action}'."),
            };
        } catch (\Throwable $ex) {
            $this->error($ex->getMessage());

            return self::FAILURE;
        }
        if ($action !== 'show') {
            $this->info("Revision #{$rev->id}: {$action} done (now {$rev->fresh()->status}).");
        }

        return self::SUCCESS;
    }

    private function show(PostRevision $rev): void
    {
        $fc = (array) $rev->fact_check;
        $this->info("Revision #{$rev->id} of post #{$rev->post_id} [{$rev->status}] policy v{$rev->policy_version}");
        $this->line('OLD TITLE: ' . ($rev->snapshot['title'] ?? ''));
        $this->line('NEW TITLE: ' . $rev->title);
        $this->line('SUMMARY:   ' . ($rev->change_log['summary'] ?? ''));
        foreach (array_values((array) ($rev->change_log['corrections'] ?? [])) as $k => $c) {
            $this->line('PROPOSED CORRECTION ' . ($k + 1) . ' (not published unless confirmed): ' . $c);
        }
        $this->line("FACT-CHECK: {$fc['verdict']} · claims checked " . ($fc['claims_checked'] ?? 0) . ' · critical ' . ($fc['critical'] ?? 0));
        foreach ((array) ($fc['issues'] ?? []) as $i) {
            $this->line("  [{$i['severity']}] {$i['claim']} — {$i['problem']}");
        }
        foreach ((array) $rev->sources as $s) {
            $this->line('SOURCE: ' . ($s['name'] ?? '?') . ' — ' . ($s['title'] ?? '') . ' — ' . ($s['url'] ?? ''));
        }
        $this->newLine();
        $this->line(trim(strip_tags(str_replace(['</p>', '</h2>'], ["\n\n", "\n"], preg_replace('/<h2[^>]*>/', '## ', $rev->body)))));
    }
}
