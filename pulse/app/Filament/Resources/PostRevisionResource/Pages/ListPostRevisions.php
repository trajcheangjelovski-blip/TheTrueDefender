<?php

namespace App\Filament\Resources\PostRevisionResource\Pages;

use App\Filament\Resources\PostRevisionResource;
use Filament\Resources\Pages\ListRecords;

class ListPostRevisions extends ListRecords
{
    protected static string $resource = PostRevisionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
