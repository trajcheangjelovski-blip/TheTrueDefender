<?php

namespace App\Filament\Resources\IngestedItemResource\Pages;

use App\Filament\Resources\IngestedItemResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditIngestedItem extends EditRecord
{
    protected static string $resource = IngestedItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
