<?php

namespace App\Console\Commands;

use App\Jobs\IngestSourceJob;
use App\Models\IngestSource;
use App\Services\IngestService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ingest:run {--sync : Ingest every feed in this process, one after another (old behaviour)}')]
#[Description('Fetch active news feeds, AI-rewrite new items, and create posts (queues one parallel job per feed)')]
class IngestRun extends Command
{
    public function handle(IngestService $ingest): int
    {
        if ($this->option('sync')) {
            $this->info('Running news ingest…');
            $count = $ingest->runAll();
            $this->info("Ingest complete — {$count} new post(s) created.");

            return self::SUCCESS;
        }

        // One job per feed on the `ingest` queue; feeds still being processed are
        // skipped by the job's uniqueness lock, so dispatching every minute is safe.
        $ids = IngestSource::where('is_active', true)->pluck('id');
        foreach ($ids as $id) {
            IngestSourceJob::dispatch($id);
        }
        $this->info("Queued ingest for {$ids->count()} feed(s).");

        return self::SUCCESS;
    }
}
