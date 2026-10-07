<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use App\Filament\Actions\ScanRunActions;
use App\Filament\Actions\StartTargetScanAction;
use App\Filament\Resources\Scans\ScanResource;
use App\Filament\Resources\Scans\Tables\ScanColumns;
use App\Models\Asset;
use App\Models\Scan;
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
                ->withCount(Scan::severityCountsForQuery()))
            ->recordUrl(fn (Scan $record): string => ScanResource::getUrl('view', ['record' => $record]))
            ->columns(ScanColumns::columns())
            ->filters(ScanColumns::filters())
            ->headerActions([
                StartTargetScanAction::make('startScanFromRelation')
                    ->record(fn (): Asset => $this->getOwnerRecord())
                    ->visible(fn (): bool => $this->getOwnerRecord()->isVerified()),
            ])
            ->recordActions([
                ScanRunActions::rowMenu(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedQueueList)
            ->emptyStateHeading('Not scanned yet')
            ->emptyStateDescription(fn (): string => $this->getOwnerRecord()->isVerified()
                ? 'Start a quick scan to get a first picture in a few minutes.'
                : 'Verify DNS ownership first — then scans can start.');
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
