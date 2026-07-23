<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PollResource\Pages;
use App\Models\Poll;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PollResource extends Resource
{
    protected static ?string $model = Poll::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Audience';

    protected static ?string $navigationLabel = 'Reader Polls';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('question')->required()->maxLength(200)->columnSpanFull(),
            Forms\Components\Toggle::make('is_active')
                ->label('Active (shown on homepage)')->default(true)
                ->helperText('Only the newest active poll is displayed.'),
            Forms\Components\Repeater::make('options')
                ->relationship()
                ->schema([
                    Forms\Components\TextInput::make('label')->required(),
                    Forms\Components\TextInput::make('votes')->numeric()->default(0)->disabled()->dehydrated(),
                ])
                ->columns(2)
                ->minItems(2)->maxItems(6)
                ->reorderable('sort_order')->orderColumn('sort_order')
                ->addActionLabel('Add option')
                ->defaultItems(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('question')->searchable()->weight('bold')->limit(60),
                Tables\Columns\TextColumn::make('options_count')->counts('options')->label('Options'),
                Tables\Columns\TextColumn::make('total')->label('Votes')
                    ->getStateUsing(fn (Poll $r) => $r->options->sum('votes')),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->dateTime('M j, Y')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPolls::route('/'),
            'create' => Pages\CreatePoll::route('/create'),
            'edit' => Pages\EditPoll::route('/{record}/edit'),
        ];
    }
}
