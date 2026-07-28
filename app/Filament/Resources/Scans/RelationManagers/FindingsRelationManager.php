<?php

namespace App\Filament\Resources\Scans\RelationManagers;

use App\Enums\FindingSeverity;
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

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Finding')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('severity')
                            ->badge()
                            ->formatStateUsing(fn (FindingSeverity|string $state): string => static::severity($state)->label())
                            ->color(fn (FindingSeverity|string $state): string => static::severity($state)->color()),
                        TextEntry::make('title'),
                        TextEntry::make('source')->badge(),
                        TextEntry::make('category')->placeholder('—'),
                        TextEntry::make('cve')->placeholder('—'),
                        TextEntry::make('description')->placeholder('—'),
                    ])
                    ->columns(1),
                Section::make('Evidence')
                    ->columnSpanFull()
                    ->schema([
                        CodeEntry::make('evidence')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->grammar(Grammar::Txt)
                            ->copyable()
                            ->copyMessage('Evidence copied')
                            ->placeholder('No evidence captured.')
                            ->formatStateUsing(function (?array $state, Finding $record): ?string {
                                $lines = $record->evidenceDetailLines();

                                if ($lines !== []) {
                                    return implode("\n", $lines);
                                }

                                if ($state === null || $state === []) {
                                    return null;
                                }

                                return json_encode(
                                    $state,
                                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                                );
                            }),
                    ])
                    ->columns(1),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort(
                function (Builder $query, string $direction): Builder {
                    $direction = $direction === 'asc' ? 'asc' : 'desc';

                    return $query->orderByRaw(FindingSeverity::orderByRankSql().' '.$direction);
                },
                'desc',
            )
            ->recordTitleAttribute('title')
            ->recordAction('view')
            ->columns([
                TextColumn::make('severity')
                    ->badge()
                    ->formatStateUsing(fn (FindingSeverity $state): string => $state->label())
                    ->color(fn (FindingSeverity $state): string => $state->color())
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $direction = $direction === 'asc' ? 'asc' : 'desc';

                        return $query->orderByRaw(FindingSeverity::orderByRankSql().' '.$direction);
                    }),
                TextColumn::make('title')
                    ->searchable()
                    ->wrap()
                    ->description(fn (Finding $record): ?string => $record->description),
                TextColumn::make('source')->badge()->color('gray'),
                TextColumn::make('category')->toggleable(),
                TextColumn::make('cve')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('severity')->options([
                    FindingSeverity::High->value => 'HIGH',
                    FindingSeverity::Medium->value => 'MEDIUM',
                    FindingSeverity::Low->value => 'LOW',
                ]),
                SelectFilter::make('source')->options([
                    'nmap' => 'nmap',
                    'nuclei' => 'nuclei',
                    'zap' => 'zap',
                    'dns' => 'dns',
                    'http' => 'http',
                ]),
            ])
            ->headerActions([])
            ->recordActions([
                ViewAction::make()
                    ->slideOver()
                    ->modalWidth('3xl'),
            ])
            ->toolbarActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    private static function severity(FindingSeverity|string $state): FindingSeverity
    {
        return $state instanceof FindingSeverity
            ? $state
            : FindingSeverity::from($state);
    }
}
