<?php

namespace App\Filament\Resources\Scans;

use App\Enums\ScanProfile;
use App\Enums\ScanStatus;
use App\Filament\Resources\Scans\Pages\ManageScans;
use App\Models\Scan;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ScanResource extends Resource
{
    protected static ?string $model = Scan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'Scans';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('id')->label('Scan'),
            TextEntry::make('asset.value')->label('Target'),
            TextEntry::make('profile')->badge(),
            TextEntry::make('status')->badge()
                ->color(fn (ScanStatus $state): string => match ($state) {
                    ScanStatus::Completed => 'success',
                    ScanStatus::Running => 'warning',
                    ScanStatus::Failed => 'danger',
                    default => 'gray',
                }),
            TextEntry::make('progress')
                ->label('Progress')
                ->state(fn (Scan $record) => $record->progressPercent().'% ('.$record->finishedTasksCount().'/'.$record->totalTasksCount().')'),
            TextEntry::make('started_at')->dateTime()->placeholder('—'),
            TextEntry::make('finished_at')->dateTime()->placeholder('—'),
            TextEntry::make('error_message')->columnSpanFull()->placeholder('—'),
            RepeatableEntry::make('tasks')
                ->label('Tasks')
                ->schema([
                    TextEntry::make('type')->badge(),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('scheduled_at')->dateTime()->label('Scheduled'),
                    TextEntry::make('started_at')->dateTime()->placeholder('—'),
                    TextEntry::make('finished_at')->dateTime()->placeholder('—'),
                    TextEntry::make('error_message')->placeholder('—')->columnSpanFull(),
                ])
                ->columns(3)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('3s')
            ->modifyQueryUsing(fn ($query) => $query->with(['asset', 'tasks']))
            ->columns([
                TextColumn::make('id')->sortable()->label('#'),
                TextColumn::make('asset.value')
                    ->label('Target')
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('profile')
                    ->badge()
                    ->color('info'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (ScanStatus $state): string => match ($state) {
                        ScanStatus::Completed => 'success',
                        ScanStatus::Running => 'warning',
                        ScanStatus::Failed => 'danger',
                        ScanStatus::Cancelled => 'gray',
                        default => 'gray',
                    }),
                ViewColumn::make('progress')
                    ->label('Progress')
                    ->view('filament.tables.columns.scan-progress')
                    ->state(fn (Scan $record) => $record->progressPercent()),
                TextColumn::make('findings_count')
                    ->counts('findings')
                    ->label('Findings')
                    ->badge()
                    ->color('warning'),
                TextColumn::make('created_at')->since()->sortable()->label('Started'),
            ])
            ->filters([
                SelectFilter::make('profile')->options(collect(ScanProfile::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value])),
                SelectFilter::make('status')->options(collect(ScanStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value])),
            ])
            ->recordActions([
                ViewAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageScans::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
