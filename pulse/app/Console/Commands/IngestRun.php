<?php

namespace App\Console\Commands;

use App\Services\IngestService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ingest:run')]
#[Description('Fetch active news feeds, AI-rewrite new items, and create posts')]
class IngestRun extends Command
{
    public function handle(IngestService $ingest): int
    {
        $this->info('Running news ingest…');
        $count = $ingest->runAll();
        $this->info("Ingest complete — {$count} new post(s) created.");

        return self::SUCCESS;
    }
}
