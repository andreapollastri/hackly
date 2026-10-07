<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Scans\ScanResource;
use App\Models\Scan;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentTargetScans extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 2];

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent target scans')
            ->query(fn () => Scan::query()
                ->with(['asset', 'tasks'])
                ->withCount(Scan::severityCountsForQuery())
                ->latest())
            ->poll('10s')
            ->recordUrl(fn (Scan $record): string => ScanResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('asset.value')
                    ->label('Target')
                    ->weight('medium')
                    ->description(fn (Scan $record): string => $record->profile->getLabel().' · '.$record->created_at->diffForHumans()),
                TextColumn::make('status')->badge(),
                ViewColumn::make('findings_summary')
                    ->label('Issues')
                    ->view('filament.tables.columns.scan-findings-summary')
                    ->state(fn (Scan $record): array => $record->findingsSeveritySummary()),
            ])
            ->headerActions([
                Action::make('all')
                    ->label('All scans')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->iconPosition('after')
                    ->link()
                    ->url(ScanResource::getUrl('index')),
            ])
            ->paginated(false)
            ->defaultPaginationPageOption(5)
            ->modifyQueryUsing(fn ($query) => $query->limit(5))
            ->emptyStateIcon(Heroicon::OutlinedGlobeAlt)
            ->emptyStateHeading('No target scans yet');
    }
}
