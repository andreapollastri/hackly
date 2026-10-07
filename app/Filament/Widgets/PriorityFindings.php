<?php

namespace App\Filament\Widgets;

use App\Enums\FindingSeverity;
use App\Filament\Resources\Findings\Actions\TriageActions;
use App\Filament\Resources\Findings\FindingResource;
use App\Models\Finding;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class PriorityFindings extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Needs attention')
            ->description('Unresolved high and medium issues, most severe and most recent first.')
            ->query(fn () => Finding::query()
                ->issues()
                ->unresolved()
                ->whereIn('severity', [FindingSeverity::High, FindingSeverity::Medium])
                ->with(['asset', 'repository'])
                ->reorder()
                ->orderByRaw(FindingSeverity::orderByRankSql().' desc')
                ->orderByDesc('created_at'))
            ->recordUrl(fn (Finding $record): string => FindingResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('severity')->badge(),
                TextColumn::make('title')
                    ->label('Finding')
                    ->weight('medium')
                    ->wrap()
                    ->description(fn (Finding $record): string => $record->subjectName()),
                TextColumn::make('source')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')
                    ->label('First seen')
                    ->since()
                    ->dateTimeTooltip()
                    ->color('gray'),
            ])
            ->recordActions([
                ActionGroup::make(TriageActions::make())->tooltip('Triage'),
            ])
            ->headerActions([
                Action::make('all')
                    ->label('All findings')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->iconPosition('after')
                    ->link()
                    ->url(FindingResource::getUrl('index')),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading('Nothing urgent')
            ->emptyStateDescription('No open high or medium issues. Keep scanning regularly to keep it that way.');
    }
}
