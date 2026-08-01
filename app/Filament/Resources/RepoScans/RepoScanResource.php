<?php

namespace App\Filament\Resources\RepoScans;

use App\Enums\FindingSeverity;
use App\Enums\ScanProfile;
use App\Enums\ScanStatus;
use App\Enums\ScanTaskStatus;
use App\Filament\Resources\RepoScans\Pages\ManageRepoScans;
use App\Filament\Resources\RepoScans\Pages\ViewRepoScan;
use App\Filament\Resources\RepoScans\RelationManagers\FindingsRelationManager;
use App\Models\RepoScan;
use App\Models\RepoScanTask;
use BackedEnum;
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

class RepoScanResource extends Resource
{
    protected static ?string $model = RepoScan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracket;

    protected static ?string $navigationLabel = 'Repo scans';

    protected static ?string $modelLabel = 'Repo scan';

    protected static ?string $pluralModelLabel = 'Repo scans';

    protected static ?string $slug = 'repo-scans';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('id')->label('Scan')->copyable(),
            TextEntry::make('repository.full_name')->label('Repository'),
            TextEntry::make('profile')->badge(),
            TextEntry::make('status')->badge()
                ->color(fn (ScanStatus $state): string => match ($state) {
                    ScanStatus::Completed => 'success',
                    ScanStatus::Running => 'warning',
                    ScanStatus::Failed => 'danger',
                    default => 'gray',
                }),
            TextEntry::make('commit_sha')
                ->label('Commit')
                ->placeholder('—')
                ->copyable()
                ->limit(12)
                ->tooltip(fn (RepoScan $record): ?string => $record->commit_sha),
            TextEntry::make('progress')
                ->label('Progress')
                ->state(fn (RepoScan $record) => $record->progressPercent().'% ('.$record->finishedTasksCount().'/'.$record->totalTasksCount().')'),
            TextEntry::make('started_at')->dateTime()->placeholder('—'),
            TextEntry::make('finished_at')->dateTime()->placeholder('—'),
            TextEntry::make('error_message')->columnSpanFull()->placeholder('—'),
            RepeatableEntry::make('tasks')
                ->label('Tasks')
                ->contained(false)
                ->schema([
                    Section::make(fn (?RepoScanTask $record): string => $record?->type?->label() ?? $record?->type?->value ?? 'Task')
                        ->description(fn (?RepoScanTask $record): ?string => static::taskAccordionDescription($record))
                        ->icon(fn (?RepoScanTask $record): Heroicon => match ($record?->status) {
                            ScanTaskStatus::Completed => Heroicon::OutlinedCheckCircle,
                            ScanTaskStatus::Failed => Heroicon::OutlinedXCircle,
                            ScanTaskStatus::Running => Heroicon::OutlinedArrowPath,
                            ScanTaskStatus::Skipped => Heroicon::OutlinedMinusCircle,
                            default => Heroicon::OutlinedClock,
                        })
                        ->iconColor(fn (?RepoScanTask $record): string => match ($record?->status) {
                            ScanTaskStatus::Completed => 'success',
                            ScanTaskStatus::Failed => 'danger',
                            ScanTaskStatus::Running, ScanTaskStatus::Queued => 'warning',
                            default => 'gray',
                        })
                        ->extraAttributes(fn (?RepoScanTask $record): array => [
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

    protected static function taskAccordionDescription(?RepoScanTask $record): ?string
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
                ->with(['repository', 'tasks'])
                ->withCount([
                    'findings as high_findings_count' => fn ($q) => $q->where('severity', FindingSeverity::High)->whereNotIn('category', ['passed', 'scan_diff']),
                    'findings as medium_findings_count' => fn ($q) => $q->where('severity', FindingSeverity::Medium)->whereNotIn('category', ['passed', 'scan_diff']),
                    'findings as low_findings_count' => fn ($q) => $q->where('severity', FindingSeverity::Low)->whereNotIn('category', ['passed', 'scan_diff']),
                ]))
            ->recordUrl(fn (RepoScan $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('id')
                    ->label('UUID')
                    ->copyable()
                    ->limit(8)
                    ->tooltip(fn (RepoScan $record) => $record->id)
                    ->searchable(),
                TextColumn::make('repository.full_name')
                    ->label('Repository')
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
                    ->state(fn (RepoScan $record) => $record->findingsSeveritySummary()),
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
            'index' => ManageRepoScans::route('/'),
            'view' => ViewRepoScan::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
