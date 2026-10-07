<?php

namespace App\Filament\Resources\Repositories\RelationManagers;

use App\Filament\Actions\ScanRunActions;
use App\Filament\Actions\StartRepositoryScanAction;
use App\Filament\Resources\RepoScans\RepoScanResource;
use App\Filament\Resources\Scans\Tables\ScanColumns;
use App\Models\RepoScan;
use App\Models\Repository;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ScansRelationManager extends RelationManager
{
    protected static string $relationship = 'scans';

    protected static ?string $title = 'Scans';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedQueueList;

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->scans()->count();
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'gray';
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('5s')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['tasks'])
                ->withCount(RepoScan::severityCountsForQuery()))
            ->recordUrl(fn (RepoScan $record): string => RepoScanResource::getUrl('view', ['record' => $record]))
            ->columns(ScanColumns::columns())
            ->filters(ScanColumns::filters())
            ->headerActions([
                StartRepositoryScanAction::make('startScanFromRelation')
                    ->record(fn (): Repository => $this->getOwnerRecord()),
            ])
            ->recordActions([
                ScanRunActions::rowMenu(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedQueueList)
            ->emptyStateHeading('Not scanned yet')
            ->emptyStateDescription('Run a quick scan for secrets and vulnerable dependencies — it only takes a minute.');
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
