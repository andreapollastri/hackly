<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use App\Filament\Resources\Findings\Schemas\FindingInfolist;
use App\Filament\Resources\Findings\Tables\FindingsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class FindingsRelationManager extends RelationManager
{
    protected static string $relationship = 'findings';

    protected static ?string $title = 'Findings';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedBugAnt;

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $open = $ownerRecord->findings()->reorder()->issues()->open()->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'danger';
    }

    public function infolist(Schema $schema): Schema
    {
        return FindingInfolist::configure($schema);
    }

    public function table(Table $table): Table
    {
        return FindingsTable::configure($table, context: 'target', showSubject: false)
            ->emptyStateDescription('No issues recorded for this target yet. Run a scan to build the picture.');
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
