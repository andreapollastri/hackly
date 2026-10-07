<?php

namespace App\Filament\Resources\Findings\Tables;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\Reachability;
use App\Filament\Resources\Findings\Actions\TriageActions;
use App\Filament\Resources\Findings\Schemas\FindingInfolist;
use App\Models\Finding;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * One findings table used everywhere: the global Findings page, and the findings
 * tabs on targets, repositories and individual scans.
 */
class FindingsTable
{
    /**
     * @param  'all'|'target'|'repository'  $context  which subject columns/filters make sense
     */
    public static function configure(Table $table, string $context = 'all', bool $showSubject = true): Table
    {
        $isRepo = $context === 'repository';
        $showRepoColumns = $context !== 'target';

        return $table
            ->recordTitleAttribute('title')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['asset', 'repository']))
            ->defaultSort(
                fn (Builder $query, string $direction): Builder => $query
                    ->reorder()
                    ->orderByRaw(FindingSeverity::orderByRankSql('findings.severity').' '.($direction === 'asc' ? 'asc' : 'desc'))
                    ->orderByDesc('findings.created_at'),
                'desc',
            )
            ->columns([
                TextColumn::make('severity')
                    ->badge()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->reorder()
                        ->orderByRaw(FindingSeverity::orderByRankSql('findings.severity').' '.($direction === 'asc' ? 'asc' : 'desc'))),
                TextColumn::make('title')
                    ->label('Finding')
                    ->searchable()
                    ->weight('medium')
                    ->wrap()
                    ->description(fn (Finding $record): ?string => $record->description
                        ? str($record->description)->limit(110)->toString()
                        : null),
                TextColumn::make('subject')
                    ->label('Asset')
                    ->state(fn (Finding $record): string => $record->subjectName())
                    ->icon(fn (Finding $record): Heroicon => $record->isRepositoryFinding() ? Heroicon::OutlinedCodeBracketSquare : Heroicon::OutlinedGlobeAlt)
                    ->iconColor('gray')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q) => $q
                        ->whereHas('asset', fn (Builder $a) => $a->where('value', 'like', "%{$search}%"))
                        ->orWhereHas('repository', fn (Builder $r) => $r->where('full_name', 'like', "%{$search}%"))))
                    ->visible($showSubject),
                TextColumn::make('source')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('reachability')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: ! $isRepo)
                    ->visible($showRepoColumns),
                TextColumn::make('cve')
                    ->label('CVE')
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('category')
                    ->formatStateUsing(fn (?string $state): string => Finding::categoryLabel($state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('First seen')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('severity')
                    ->options(FindingSeverity::class)
                    ->multiple(),
                SelectFilter::make('status')
                    ->options(FindingStatus::class)
                    ->multiple(),
                SelectFilter::make('source')
                    ->options(fn (): array => Finding::query()
                        ->reorder()
                        ->distinct()
                        ->orderBy('source')
                        ->pluck('source', 'source')
                        ->all())
                    ->multiple(),
                SelectFilter::make('asset')
                    ->label('Target')
                    ->relationship('asset', 'value')
                    ->searchable()
                    ->preload()
                    ->visible($context === 'all'),
                SelectFilter::make('repository')
                    ->relationship('repository', 'full_name')
                    ->searchable()
                    ->preload()
                    ->visible($context === 'all'),
                SelectFilter::make('reachability')
                    ->options(Reachability::class)
                    ->visible($showRepoColumns),
                TernaryFilter::make('passed_checks')
                    ->label('Passed checks')
                    ->placeholder('Hide passed checks')
                    ->trueLabel('Show passed checks too')
                    ->falseLabel('Only passed checks')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q->whereNull('category')->orWhere('category', '!=', 'scan_diff')),
                        false: fn (Builder $query): Builder => $query->where('category', 'passed'),
                        blank: fn (Builder $query): Builder => $query->issues(),
                    ),
            ])
            ->groups([
                Group::make('severity')
                    ->getTitleFromRecordUsing(fn (Finding $record): string => $record->severity->getLabel())
                    ->orderQueryUsing(fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw(FindingSeverity::orderByRankSql('findings.severity').' '.($direction === 'asc' ? 'asc' : 'desc'))),
                Group::make('status')
                    ->getTitleFromRecordUsing(fn (Finding $record): string => $record->status->getLabel()),
                Group::make('source'),
            ])
            // Rows open the triage slide-over; the full page stays reachable for deep links.
            ->recordUrl(null)
            ->recordAction('view')
            ->recordActions([
                ViewAction::make()
                    ->label('Details')
                    ->iconButton()
                    ->icon(Heroicon::OutlinedEye)
                    ->slideOver()
                    ->modalHeading(fn (Finding $record): string => $record->severity->getLabel().' finding · '.$record->subjectName())
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->schema(fn (Schema $schema): Schema => FindingInfolist::configure($schema))
                    ->modalFooterActions(fn (ViewAction $action): array => [
                        ...TriageActions::make(closesParent: true),
                        $action->getModalCancelAction()->label('Close'),
                    ]),
                ActionGroup::make(TriageActions::make())
                    ->tooltip('Triage')
                    ->icon(Heroicon::OutlinedEllipsisVertical),
            ])
            ->toolbarActions([
                TriageActions::bulkGroup(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading('No findings here')
            ->emptyStateDescription('Nothing matches the current view. Try another tab or clear the filters.')
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25);
    }
}
