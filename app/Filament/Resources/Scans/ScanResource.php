<?php

namespace App\Filament\Resources\Scans;

use App\Enums\FindingSeverity;
use App\Enums\ScanProfile;
use App\Enums\ScanStatus;
use App\Filament\Resources\Scans\Pages\ManageScans;
use App\Filament\Resources\Scans\Pages\ViewScan;
use App\Filament\Resources\Scans\RelationManagers\FindingsRelationManager;
use App\Models\Scan;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            TextEntry::make('id')->label('Scan')->copyable(),
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
            ->defaultSort('created_at', 'desc')
            ->poll('3s')
            ->modifyQueryUsing(fn ($query) => $query->with(['asset', 'tasks']))
            ->recordUrl(fn (Scan $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('id')
                    ->label('UUID')
                    ->copyable()
                    ->limit(8)
                    ->tooltip(fn (Scan $record) => $record->id)
                    ->searchable(),
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
                    ->color('gray'),
                TextColumn::make('created_at')->since()->sortable()->label('Started'),
            ])
            ->filters([
                SelectFilter::make('profile')->options(collect(ScanProfile::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value])),
                SelectFilter::make('status')->options(collect(ScanStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value])),
            ])
            ->recordActions([
                Action::make('exportPdf')
                    ->label('PDF')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->color('gray')
                    ->action(fn (Scan $record): StreamedResponse => static::downloadReport($record)),
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            FindingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageScans::route('/'),
            'view' => ViewScan::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function downloadReport(Scan $scan): StreamedResponse
    {
        $scan->load(['asset', 'tasks', 'findings', 'requester']);

        $findings = $scan->findings
            ->sortByDesc(fn ($finding) => $finding->severity->rank())
            ->values();

        $summary = [
            'high' => $findings->filter(fn ($f) => $f->severity === FindingSeverity::High)->count(),
            'medium' => $findings->filter(fn ($f) => $f->severity === FindingSeverity::Medium)->count(),
            'low' => $findings->filter(fn ($f) => $f->severity === FindingSeverity::Low)->count(),
        ];

        $pdf = Pdf::loadView('reports.scan', [
            'scan' => $scan,
            'findings' => $findings,
            'summary' => $summary,
            'generatedAt' => now(),
        ])->setPaper('a4');

        $filename = 'hackly-scan-'.substr($scan->id, 0, 8).'.pdf';

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }
}
