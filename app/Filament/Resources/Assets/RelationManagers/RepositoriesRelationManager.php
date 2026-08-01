<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RepositoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'repositories';

    protected static ?string $title = 'Linked repositories';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('full_name')
            ->columns([
                TextColumn::make('full_name')->searchable(),
                TextColumn::make('default_branch')->badge(),
                IconColumn::make('nightly_enabled')->boolean()->label('Nightly'),
                TextColumn::make('last_scanned_at')->since()->placeholder('Never'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['full_name', 'owner', 'name']),
            ])
            ->recordActions([
                DetachAction::make(),
            ]);
    }
}
