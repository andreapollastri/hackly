<?php

namespace App\Filament\Resources\Repositories\RelationManagers;

use App\Enums\FindingSeverity;
use App\Enums\Reachability;
use App\Models\Finding;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Phiki\Grammar\Grammar;

class FindingsRelationManager extends RelationManager
{
    protected static string $relationship = 'findings';

    protected static ?string $title = 'Findings';

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Finding')
                    ->schema([
                        TextEntry::make('severity')->badge()
                            ->formatStateUsing(fn (FindingSeverity $state): string => $state->label())
                            ->color(fn (FindingSeverity $state): string => $state->color()),
                        TextEntry::make('reachability')->badge()
                            ->formatStateUsing(fn (?Reachability $state): string => $state?->label() ?? '—')
                            ->color(fn (?Reachability $state): string => $state?->color() ?? 'gray'),
                        TextEntry::make('confidence')->placeholder('—'),
                        TextEntry::make('title'),
                        TextEntry::make('source')->badge(),
                        TextEntry::make('category')->placeholder('—'),
                        TextEntry::make('cve')->placeholder('—'),
                        TextEntry::make('noise_filtered')->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Noise annotated' : 'Signal')
                            ->color(fn (bool $state): string => $state ? 'warning' : 'success'),
                        TextEntry::make('description')->placeholder('—'),
                    ]),
                Section::make('Evidence')
                    ->schema([
                        CodeEntry::make('evidence')
                            ->hiddenLabel()
                            ->grammar(Grammar::Txt)
                            ->copyable()
                            ->formatStateUsing(function (?array $state, Finding $record): ?string {
                                $lines = $record->evidenceDetailLines();

                                return $lines !== []
                                    ? implode("\n", $lines)
                                    : ($state ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : null);
                            }),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where('category', '!=', 'passed'))
            ->defaultSort(
                function (Builder $query, string $direction): Builder {
                    $direction = $direction === 'asc' ? 'asc' : 'desc';

                    return $query->orderByRaw(FindingSeverity::orderByRankSql().' '.$direction);
                },
                'desc',
            )
            ->columns([
                TextColumn::make('severity')->badge()
                    ->formatStateUsing(fn (FindingSeverity $state): string => $state->label())
                    ->color(fn (FindingSeverity $state): string => $state->color()),
                TextColumn::make('title')->searchable()->wrap()->limit(80),
                TextColumn::make('source')->badge(),
                TextColumn::make('reachability')->badge()
                    ->formatStateUsing(fn (?Reachability $state): string => $state?->label() ?? '—')
                    ->color(fn (?Reachability $state): string => $state?->color() ?? 'gray'),
                TextColumn::make('cve')->placeholder('—'),
                TextColumn::make('noise_filtered')->label('Noise')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'yes' : 'no')
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('severity')->options([
                    FindingSeverity::High->value => 'High',
                    FindingSeverity::Medium->value => 'Medium',
                    FindingSeverity::Low->value => 'Low',
                ]),
                SelectFilter::make('reachability')->options([
                    Reachability::Reachable->value => 'Reachable',
                    Reachability::Unreachable->value => 'Unreachable',
                    Reachability::Unknown->value => 'Unknown',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
