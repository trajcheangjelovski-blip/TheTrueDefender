<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PostRevisionResource\Pages;
use App\Models\PostRevision;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * Editorial approval for revision drafts of published articles (from
 * posts:revise-live). Nothing changes on the live site until an editor clicks
 * "Approve & publish"; the editor's account is recorded on the revision.
 */
class PostRevisionResource extends Resource
{
    protected static ?string $model = PostRevision::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Revisions';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = PostRevision::where('status', 'pending_review')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Article (new headline)')->limit(70)->searchable()->weight('bold')
                    ->description(fn (PostRevision $record) => 'Was: ' . \Illuminate\Support\Str::limit($record->snapshot['title'] ?? '', 70)),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'pending_review' => 'Ready for review',
                        'needs_rewrite' => 'Needs rewriting',
                        'needs_research' => 'Needs research',
                        'rejected' => 'Rejected',
                        'applied' => 'Published',
                        'rolled_back' => 'Rolled back',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'pending_review' => 'warning',
                        'applied' => 'success',
                        'needs_rewrite', 'needs_research' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('fact_check.critical')->label('Critical')->badge()
                    ->color(fn ($state) => (int) $state > 0 ? 'danger' : 'success'),
                Tables\Columns\TextColumn::make('corrections')->label('Corrections')
                    ->getStateUsing(fn (PostRevision $record) => count((array) ($record->changes['corrections'] ?? []))),
                Tables\Columns\TextColumn::make('story_type')->label('Type')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('reviewer.name')->label('Approved by')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->dateTime('M j, H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending_review' => 'Ready for review', 'needs_rewrite' => 'Needs rewriting',
                    'needs_research' => 'Needs research', 'rejected' => 'Rejected',
                    'applied' => 'Published', 'rolled_back' => 'Rolled back',
                ])->default('pending_review'),
            ])
            ->actions([
                Tables\Actions\Action::make('review')
                    ->label('Review')
                    ->icon('heroicon-m-eye')
                    ->modalHeading(fn (PostRevision $record) => 'Revision #' . $record->id . ' — ' . $record->title)
                    ->modalWidth('7xl')
                    ->modalContent(fn (PostRevision $record) => view('filament.revision-review', ['rev' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Tables\Actions\Action::make('approve')
                    ->label('Approve & publish')->icon('heroicon-m-check')->color('success')
                    ->visible(fn (PostRevision $record) => $record->status === 'pending_review')
                    ->requiresConfirmation()
                    ->modalDescription('This replaces the live article with this revision (URL, date and image stay the same). You can roll it back later.')
                    ->action(fn (PostRevision $record) => self::approve($record)),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')->icon('heroicon-m-x-mark')->color('gray')
                    ->visible(fn (PostRevision $record) => ! in_array($record->status, ['applied', 'rejected', 'rolled_back'], true))
                    ->requiresConfirmation()
                    ->action(fn (PostRevision $record) => $record->reject(auth()->id())),
                Tables\Actions\Action::make('rollback')
                    ->label('Roll back')->icon('heroicon-m-arrow-uturn-left')->color('danger')
                    ->visible(fn (PostRevision $record) => $record->status === 'applied')
                    ->requiresConfirmation()
                    ->modalDescription('Restores the article exactly as it was before this revision.')
                    ->action(fn (PostRevision $record) => $record->rollback()),
                Tables\Actions\Action::make('open')
                    ->label('Live article')->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (PostRevision $record) => $record->post ? route('post.show', $record->post) : null, true),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('approveSelected')
                    ->label('Approve & publish selected')->icon('heroicon-m-check')->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Only revisions marked "Ready for review" are published; others are skipped.')
                    ->action(function (Collection $records) {
                        $n = 0;
                        foreach ($records->where('status', 'pending_review') as $r) {
                            $r->apply(auth()->id());
                            $n++;
                        }
                        Notification::make()->title("Published {$n} revision(s)")->success()->send();
                    }),
            ]);
    }

    private static function approve(PostRevision $r): void
    {
        $r->apply(auth()->id());
        Notification::make()->title('Revision published')->body($r->title)->success()->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPostRevisions::route('/'),
        ];
    }
}
