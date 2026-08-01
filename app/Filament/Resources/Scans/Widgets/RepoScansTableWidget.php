<?php

namespace App\Filament\Resources\Scans\Widgets;

use App\Filament\Resources\RepoScans\RepoScanResource;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Support\Htmlable;

class RepoScansTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string|Htmlable|null
    {
        return null;
    }

    public function table(Table $table): Table
    {
        return RepoScanResource::table($table)
            ->query(RepoScanResource::getEloquentQuery())
            ->heading(null);
    }
}
