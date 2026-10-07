<?php

namespace App\Filament\Resources\Scans;

use App\Filament\Actions\ScanRunActions;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Scans\Pages\ManageScans;
use App\Filament\Resources\Scans\Pages\ViewScan;
use App\Filament\Resources\Scans\RelationManagers\FindingsRelationManager;
use App\Filament\Resources\Scans\Tables\ScanColumns;
use App\Filament\Support\NavigationGroup;
use App\Models\Finding;
use App\Models\RepoScan;
use App\Models\Scan;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class ScanResource extends Resource
{
    protected static ?string $model = Scan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Security;

    protected static ?string $navigationLabel = 'Scans';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $running = once(fn (): int => Scan::query()->whereIn('status', ['pending', 'running'])->count()
            + RepoScan::query()->whereIn('status', ['pending', 'running'])->count());

        return $running > 0 ? (string) $running : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'info';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Scans in progress';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components(static::scanDetailComponents());
    }

    /**
     * Summary card + task timeline, shared with repository scans.
     *
     * @return list<Section|ViewEntry>
     */
    public static function scanDetailComponents(): array
    {
        return [
            ViewEntry::make('summary')
                ->hiddenLabel()
                ->view('filament.scans.summary'),
            Section::make('Tasks')
                ->description('Each scanner runs as its own queued job, spaced out by the soft rate limits.')
                ->icon(Heroicon::OutlinedListBullet)
                ->compact()
                ->collapsible()
                ->schema([
                    ViewEntry::make('tasks')
                        ->hiddenLabel()
                        ->view('filament.scans.tasks'),
                ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('5s')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['asset', 'tasks'])
                ->withCount(Scan::severityCountsForQuery()))
            ->recordUrl(fn (Scan $record): string => static::getUrl('view', ['record' => $record]))
            ->columns(ScanColumns::columns(
                TextColumn::make('asset.value')
                    ->label('Target')
                    ->icon(Heroicon::OutlinedGlobeAlt)
                    ->iconColor('gray')
                    ->weight('medium')
                    ->searchable()
                    ->url(fn (Scan $record): ?string => $record->asset ? AssetResource::getUrl('view', ['record' => $record->asset]) : null),
            ))
            ->filters(ScanColumns::filters())
            ->recordActions([
                ScanRunActions::rowMenu(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedQueueList)
            ->emptyStateHeading('No target scans yet')
            ->emptyStateDescription('Verify a target, then start a quick, standard or deep scan. Progress shows up here live.');
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
            ->filter(fn (Finding $finding): bool => $finding->category !== 'scan_diff')
            ->sortByDesc(fn (Finding $finding) => $finding->severity->rank())
            ->values();

        $issues = $findings->filter(fn (Finding $finding): bool => $finding->isIssue());

        return [
            'scan' => $scan,
            'findings' => $findings,
            'summary' => [
                'high' => $issues->filter(fn (Finding $f) => $f->severity->value === 'high')->count(),
                'medium' => $issues->filter(fn (Finding $f) => $f->severity->value === 'medium')->count(),
                'low' => $issues->filter(fn (Finding $f) => $f->severity->value === 'low')->count(),
            ],
            'generatedAt' => now(),
        ];
    }
}
