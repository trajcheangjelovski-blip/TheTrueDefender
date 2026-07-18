<?php

namespace App\Filament\Resources\SocialChannelResource\Pages;

use App\Filament\Resources\SocialChannelResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSocialChannels extends ListRecords
{
    protected static string $resource = SocialChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
