<?php

namespace App\Filament\Resources\Repositories\RelationManagers;

use App\Filament\Resources\Assets\AssetResource;
use App\Models\Asset;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AssetsRelationManager extends RelationManager
{
    protected static string $relationship = 'assets';

    protected static ?string $title = 'Linked targets';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedGlobeAlt;

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->assets()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'gray';
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('value')
            ->description('Verified linked targets can be deep-scanned together with this repository, including live Laravel probes.')
            ->recordUrl(fn (Asset $record): string => AssetResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('value')
                    ->label('Domain')
                    ->icon(Heroicon::OutlinedGlobeAlt)
                    ->iconColor('gray')
                    ->weight('medium')
                    ->searchable(),
                TextColumn::make('verified_at')
                    ->label('Ownership')
                    ->badge()
                    ->state(fn (Asset $record): string => $record->isVerified() ? 'Verified' : 'Pending')
                    ->color(fn (Asset $record): string => $record->isVerified() ? 'success' : 'warning'),
                TextColumn::make('status')->badge(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Link target')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['value']),
            ])
            ->recordActions([
                DetachAction::make()->label('Unlink'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedLink)
            ->emptyStateHeading('No linked targets')
            ->emptyStateDescription('Optional: link the domains where this code is deployed.');
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
