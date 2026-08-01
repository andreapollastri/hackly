<?php

namespace App\Filament\Resources\Repositories\RelationManagers;

use App\Enums\ScanStatus;
use App\Models\RepoScan;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ScansRelationManager extends RelationManager
{
    protected static string $relationship = 'scans';

    protected static ?string $title = 'Repo scans';

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('id')->label('Scan')->copyable(),
            TextEntry::make('profile')->badge(),
            TextEntry::make('status')->badge(),
            TextEntry::make('commit_sha')->placeholder('—'),
            TextEntry::make('progress')
                ->state(fn (RepoScan $record) => $record->progressPercent().'% ('.$record->finishedTasksCount().'/'.$record->totalTasksCount().')'),
            TextEntry::make('error_message')->placeholder('—')->columnSpanFull(),
            RepeatableEntry::make('tasks')
                ->schema([
                    TextEntry::make('type')->badge(),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('error_message')->placeholder('—'),
                ])
                ->columns(3),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('Scan')->limit(8),
                TextColumn::make('profile')->badge(),
                TextColumn::make('status')->badge()
                    ->color(fn (ScanStatus $state): string => match ($state) {
                        ScanStatus::Completed => 'success',
                        ScanStatus::Running => 'warning',
                        ScanStatus::Failed => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('progress')
                    ->state(fn (RepoScan $record) => $record->progressPercent().'%'),
                TextColumn::make('created_at')->since(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
