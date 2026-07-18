<?php

namespace App\Filament\Resources\SocialChannelResource\Pages;

use App\Filament\Resources\SocialChannelResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSocialChannel extends EditRecord
{
    protected static string $resource = SocialChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
