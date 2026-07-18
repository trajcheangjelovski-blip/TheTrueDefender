<?php

namespace App\Filament\Resources\AdPlacementResource\Pages;

use App\Filament\Resources\AdPlacementResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAdPlacements extends ListRecords
{
    protected static string $resource = AdPlacementResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
