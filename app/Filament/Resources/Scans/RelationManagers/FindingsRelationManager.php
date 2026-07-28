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
        return $schema->components([
            Section::make('Finding')
                ->schema([
                    TextEntry::make('severity')
                        ->badge()
                        ->formatStateUsing(fn (FindingSeverity|string $state): string => static::severity($state)->label())
                        ->color(fn (FindingSeverity|string $state): string => static::severity($state)->color()),
                    TextEntry::make('title')->columnSpan(2),
                    TextEntry::make('source')->badge(),
                    TextEntry::make('category')->placeholder('—'),
                    TextEntry::make('cve')->placeholder('—'),
                    TextEntry::make('description')->placeholder('—')->columnSpanFull(),
                ])
                ->columns(3),
            Section::make('Evidence')
                ->schema([
                    CodeEntry::make('evidence')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->copyable()
                        ->placeholder('No evidence captured.'),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('severity', 'desc')
            ->recordTitleAttribute('title')
            ->recordAction('view')
            ->columns([
                TextColumn::make('severity')
                    ->badge()
                    ->formatStateUsing(fn (FindingSeverity $state): string => $state->label())
                    ->color(fn (FindingSeverity $state): string => $state->color())
                    ->sortable(),
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
