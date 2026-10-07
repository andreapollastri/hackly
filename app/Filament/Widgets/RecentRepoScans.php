<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\RepoScans\RepoScanResource;
use App\Filament\Resources\Scans\ScanResource;
use App\Models\RepoScan;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentRepoScans extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 2];

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent repository scans')
            ->query(fn () => RepoScan::query()
                ->with(['repository', 'tasks'])
                ->withCount(RepoScan::severityCountsForQuery())
                ->latest())
            ->poll('10s')
            ->recordUrl(fn (RepoScan $record): string => RepoScanResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('repository.full_name')
                    ->label('Repository')
                    ->weight('medium')
                    ->description(fn (RepoScan $record): string => $record->profile->getLabel().' · '.$record->created_at->diffForHumans()),
                TextColumn::make('status')->badge(),
                ViewColumn::make('findings_summary')
                    ->label('Issues')
                    ->view('filament.tables.columns.scan-findings-summary')
                    ->state(fn (RepoScan $record): array => $record->findingsSeveritySummary()),
            ])
            ->headerActions([
                Action::make('all')
                    ->label('All scans')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->iconPosition('after')
                    ->link()
                    ->url(ScanResource::getUrl('index', ['scope' => 'repositories::tab'])),
            ])
            ->paginated(false)
            ->defaultPaginationPageOption(5)
            ->modifyQueryUsing(fn ($query) => $query->limit(5))
            ->emptyStateIcon(Heroicon::OutlinedCodeBracketSquare)
            ->emptyStateHeading('No repository scans yet');
    }
}
