<?php

namespace App\Filament\Resources\Scans;

use App\Enums\FindingSeverity;
use App\Enums\ScanProfile;
use App\Enums\ScanStatus;
use App\Enums\ScanTaskStatus;
use App\Filament\Resources\Scans\Pages\ManageScans;
use App\Filament\Resources\Scans\Pages\ViewScan;
use App\Filament\Resources\Scans\RelationManagers\FindingsRelationManager;
use App\Models\Finding;
use App\Models\Scan;
use App\Models\ScanTask;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
                ->contained(false)
                ->schema([
                    Section::make(fn (?ScanTask $record): string => $record?->type?->value ?? 'Task')
                        ->description(fn (?ScanTask $record): ?string => static::taskAccordionDescription($record))
                        ->icon(fn (?ScanTask $record): Heroicon => match ($record?->status) {
                            ScanTaskStatus::Completed => Heroicon::OutlinedCheckCircle,
                            ScanTaskStatus::Failed => Heroicon::OutlinedXCircle,
                            ScanTaskStatus::Running => Heroicon::OutlinedArrowPath,
                            ScanTaskStatus::Skipped => Heroicon::OutlinedMinusCircle,
                            default => Heroicon::OutlinedClock,
                        })
                        ->iconColor(fn (?ScanTask $record): string => match ($record?->status) {
                            ScanTaskStatus::Completed => 'success',
                            ScanTaskStatus::Failed => 'danger',
                            ScanTaskStatus::Running, ScanTaskStatus::Queued => 'warning',
                            default => 'gray',
                        })
                        ->extraAttributes(fn (?ScanTask $record): array => [
                            'class' => match ($record?->status) {
                                ScanTaskStatus::Completed => 'hackly-task-accordion hackly-task-accordion--success',
                                ScanTaskStatus::Failed => 'hackly-task-accordion hackly-task-accordion--danger',
                                default => 'hackly-task-accordion',
                            },
                        ])
                        ->compact()
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            TextEntry::make('status')
                                ->badge()
                                ->color(fn (ScanTaskStatus $state): string => match ($state) {
                                    ScanTaskStatus::Completed => 'success',
                                    ScanTaskStatus::Failed => 'danger',
                                    ScanTaskStatus::Running, ScanTaskStatus::Queued => 'warning',
                                    default => 'gray',
                                }),
                            TextEntry::make('scheduled_at')->dateTime()->label('Scheduled')->placeholder('—'),
                            TextEntry::make('started_at')->dateTime()->placeholder('—'),
                            TextEntry::make('finished_at')->dateTime()->placeholder('—'),
                            TextEntry::make('error_message')
                                ->placeholder('—')
                                ->columnSpanFull()
                                ->color(fn (?string $state): string => filled($state) ? 'danger' : 'gray'),
                        ])
                        ->columns(2),
                ])
                ->columnSpanFull(),
        ]);
    }

    protected static function taskAccordionDescription(?ScanTask $record): ?string
    {
        if (! $record) {
            return null;
        }

        $status = $record->status?->value ?? 'unknown';

        if ($record->status === ScanTaskStatus::Failed && filled($record->error_message)) {
            return $status.' · '.str($record->error_message)->limit(90);
        }

        if ($record->finished_at) {
            return $status.' · finished '.$record->finished_at->diffForHumans();
        }

        if ($record->started_at) {
            return $status.' · started '.$record->started_at->diffForHumans();
        }

        if ($record->scheduled_at) {
            return $status.' · scheduled '.$record->scheduled_at->diffForHumans();
        }

        return $status;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('3s')
            ->modifyQueryUsing(fn ($query) => $query
                ->with(['asset', 'tasks'])
                ->withCount([
                    'findings as high_findings_count' => fn ($q) => $q->where('severity', FindingSeverity::High),
                    'findings as medium_findings_count' => fn ($q) => $q->where('severity', FindingSeverity::Medium),
                    'findings as low_findings_count' => fn ($q) => $q->where('severity', FindingSeverity::Low),
                ]))
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
                    ->view('filament.tables.columns.scan-progress'),
                ViewColumn::make('findings_summary')
                    ->label('Findings')
                    ->view('filament.tables.columns.scan-findings-summary')
                    ->state(fn (Scan $record) => $record->findingsSeveritySummary()),
                TextColumn::make('created_at')->since()->sortable()->label('Started'),
            ])
            ->filters([
                SelectFilter::make('profile')->options(collect(ScanProfile::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value])),
                SelectFilter::make('status')->options(collect(ScanStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value])),
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
        $payload = static::reportPayload($scan);

        $pdf = Pdf::loadView('reports.scan', $payload)->setPaper('a4');
        $filename = 'hackly-scan-'.substr($scan->id, 0, 8).'.pdf';

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    public static function downloadMarkdownReport(Scan $scan): StreamedResponse
    {
        $payload = static::reportPayload($scan);
        $markdown = view('reports.scan-md', $payload)->render();
        $filename = 'hackly-scan-'.substr($scan->id, 0, 8).'.md';

        return response()->streamDownload(
            function () use ($markdown): void {
                echo $markdown;
            },
            $filename,
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    }

    /**
     * @return array{scan: Scan, findings: Collection<int, Finding>, summary: array{high: int, medium: int, low: int}, generatedAt: Carbon}
     */
    public static function reportPayload(Scan $scan): array
    {
        $scan->loadMissing(['asset', 'tasks', 'findings', 'requester']);

        $findings = $scan->findings
            ->sortByDesc(fn ($finding) => $finding->severity->rank())
            ->values();

        return [
            'scan' => $scan,
            'findings' => $findings,
            'summary' => [
                'high' => $findings->filter(fn ($f) => $f->severity === FindingSeverity::High)->count(),
                'medium' => $findings->filter(fn ($f) => $f->severity === FindingSeverity::Medium)->count(),
                'low' => $findings->filter(fn ($f) => $f->severity === FindingSeverity::Low)->count(),
            ],
            'generatedAt' => now(),
        ];
    }
}
