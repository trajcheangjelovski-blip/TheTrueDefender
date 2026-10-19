<?php

namespace App\Jobs;

use App\Models\IngestSource;
use App\Services\IngestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Ingest ONE feed. ingest:run dispatches one of these per active source every
 * minute, and the `ingest-worker` service runs several at once, so a feed with a
 * slow rewrite no longer delays every other feed behind it. ShouldBeUnique keeps
 * at most one job per feed queued/running; the lock auto-expires (uniqueFor) so a
 * killed worker can never block a feed for good.
 */
class IngestSourceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(public int $sourceId)
    {
        $this->onConnection('redis-ingest')->onQueue('ingest');
    }

    public function uniqueId(): string
    {
        return (string) $this->sourceId;
    }

    public function handle(IngestService $ingest): void
    {
        $source = IngestSource::find($this->sourceId);
        if (! $source || ! $source->is_active) {
            return;
        }

        $created = $ingest->runSource($source);
        if ($created > 0) {
            Log::info("Ingest {$source->name}: {$created} new post(s).");
        }
    }
}
