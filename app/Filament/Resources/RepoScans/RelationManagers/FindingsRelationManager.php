<?php

namespace App\Filament\Resources\RepoScans\RelationManagers;

use App\Filament\Resources\Findings\Schemas\FindingInfolist;
use App\Filament\Resources\Findings\Tables\FindingsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FindingsRelationManager extends RelationManager
{
    protected static string $relationship = 'findings';

    protected static ?string $title = 'Findings';

    protected static string|\BackedEnum|null $icon = Heroicon::OutlinedBugAnt;

    public function infolist(Schema $schema): Schema
    {
        return FindingInfolist::configure($schema);
    }

    public function table(Table $table): Table
    {
        return FindingsTable::configure($table, context: 'repository', showSubject: false)
            ->poll(fn (): ?string => $this->getOwnerRecord()->isActive() ? '5s' : null)
            ->emptyStateDescription(fn (): string => $this->getOwnerRecord()->isActive()
                ? 'Results appear once the clone is analysed and post-processing (dedupe, reachability) finishes.'
                : 'This scan did not report any issue for the current filters.');
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
