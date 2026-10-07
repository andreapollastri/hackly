<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use App\Filament\Resources\Repositories\RepositoryResource;
use App\Models\Repository;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RepositoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'repositories';

    protected static ?string $title = 'Linked repositories';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedCodeBracketSquare;

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->repositories()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'gray';
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('full_name')
            ->description('Linked repositories can be scanned together with this target (“Also scan linked repositories”).')
            ->recordUrl(fn (Repository $record): string => RepositoryResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('full_name')
                    ->label('Repository')
                    ->icon(Heroicon::OutlinedCodeBracketSquare)
                    ->iconColor('gray')
                    ->weight('medium')
                    ->searchable(),
                TextColumn::make('default_branch')
                    ->label('Branch')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('last_scanned_at')
                    ->label('Last scan')
                    ->since()
                    ->placeholder('Never'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Link repository')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['full_name', 'owner', 'name']),
            ])
            ->recordActions([
                DetachAction::make()->label('Unlink'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedLink)
            ->emptyStateHeading('No linked repositories')
            ->emptyStateDescription('Optional: link the GitHub repository that powers this domain to correlate SAST and DAST results.');
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
