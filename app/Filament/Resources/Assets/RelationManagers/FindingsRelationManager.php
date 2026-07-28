<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
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
        return $schema->components([
            Select::make('status')
                ->options(collect(FindingStatus::cases())->mapWithKeys(fn ($c) => [$c->value => ucfirst(str_replace('_', ' ', $c->value))]))
                ->required()
                ->native(false),
            TextInput::make('title')->disabled(),
            TextInput::make('severity')->disabled(),
            TextInput::make('source')->disabled(),
            TextInput::make('category')->disabled(),
            TextInput::make('cve')->disabled(),
            Textarea::make('description')->disabled()->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn (FindingSeverity $state): string => match ($state) {
                        FindingSeverity::Critical => 'danger',
                        FindingSeverity::High => 'warning',
                        FindingSeverity::Medium => 'info',
                        FindingSeverity::Low => 'gray',
                        default => 'success',
                    })
                    ->sortable(),
                TextColumn::make('title')->searchable()->limit(50)->wrap(),
                TextColumn::make('source')->badge()->color('gray'),
                TextColumn::make('category')->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('severity')->options(collect(FindingSeverity::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value])),
                SelectFilter::make('status')->options(collect(FindingStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value])),
            ])
            ->headerActions([])
            ->recordActions([
                EditAction::make()->label('Triage'),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
