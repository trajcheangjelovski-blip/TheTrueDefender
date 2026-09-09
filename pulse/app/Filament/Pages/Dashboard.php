<?php

namespace App\Filament\Pages;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function filtersForm(Form $form): Form
    {
        return $form->schema([
            Section::make('Date range')
                ->description('Filters the views, countries and clicks widgets. Active users are always real-time.')
                ->schema([
                    DatePicker::make('startDate')
                        ->label('From')
                        ->default(now()->subDays(29))
                        ->maxDate(fn (callable $get) => $get('endDate') ?: now())
                        ->native(false),
                    DatePicker::make('endDate')
                        ->label('To')
                        ->default(now())
                        ->minDate(fn (callable $get) => $get('startDate') ?: null)
                        ->maxDate(now())
                        ->native(false),
                ])
                ->columns(2),
        ]);
    }
}
