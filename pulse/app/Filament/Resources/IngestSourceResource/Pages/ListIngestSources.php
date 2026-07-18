<?php

namespace App\Filament\Resources\IngestSourceResource\Pages;

use App\Filament\Resources\IngestSourceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIngestSources extends ListRecords
{
    protected static string $resource = IngestSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
