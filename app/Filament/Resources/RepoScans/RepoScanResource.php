<?php

namespace App\Filament\Resources\RepoScans;

use App\Enums\FindingSeverity;
use App\Filament\Actions\ScanRunActions;
use App\Filament\Resources\RepoScans\Pages\ManageRepoScans;
use App\Filament\Resources\RepoScans\Pages\ViewRepoScan;
use App\Filament\Resources\RepoScans\RelationManagers\FindingsRelationManager;
use App\Filament\Resources\Repositories\RepositoryResource;
use App\Filament\Resources\Scans\ScanResource;
use App\Filament\Resources\Scans\Tables\ScanColumns;
use App\Models\Finding;
use App\Models\RepoScan;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RepoScanResource extends Resource
{
    protected static ?string $model = RepoScan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracket;

    protected static ?string $navigationLabel = 'Repo scans';

    protected static ?string $modelLabel = 'Repository scan';

    protected static ?string $pluralModelLabel = 'Repository scans';

    protected static ?string $slug = 'repo-scans';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components(ScanResource::scanDetailComponents());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('5s')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['repository', 'tasks'])
                ->withCount(RepoScan::severityCountsForQuery()))
            ->recordUrl(fn (RepoScan $record): string => static::getUrl('view', ['record' => $record]))
            ->columns(ScanColumns::columns(
                TextColumn::make('repository.full_name')
                    ->label('Repository')
                    ->icon(Heroicon::OutlinedCodeBracketSquare)
                    ->iconColor('gray')
                    ->weight('medium')
                    ->searchable()
                    ->url(fn (RepoScan $record): ?string => $record->repository ? RepositoryResource::getUrl('view', ['record' => $record->repository]) : null),
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
            ->emptyStateIcon(Heroicon::OutlinedCodeBracket)
            ->emptyStateHeading('No repository scans yet')
            ->emptyStateDescription('Connect a GitHub token, add a repository and press “Scan now”.');
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

    public static function downloadReport(RepoScan $scan): StreamedResponse
    {
        $payload = static::reportPayload($scan);

        $pdf = Pdf::loadView('reports.repo-scan', $payload)->setPaper('a4');
        $filename = 'hackly-repo-scan-'.substr($scan->id, 0, 8).'.pdf';

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    public static function downloadMarkdownReport(RepoScan $scan): StreamedResponse
    {
        $payload = static::reportPayload($scan);
        $markdown = view('reports.repo-scan-md', $payload)->render();
        $filename = 'hackly-repo-scan-'.substr($scan->id, 0, 8).'.md';

        return response()->streamDownload(
            function () use ($markdown): void {
                echo $markdown;
            },
            $filename,
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    }

    /**
     * @return array{scan: RepoScan, findings: Collection<int, Finding>, summary: array{high: int, medium: int, low: int}, generatedAt: Carbon}
     */
    public static function reportPayload(RepoScan $scan): array
    {
        $scan->loadMissing(['repository', 'tasks', 'findings', 'requester']);

        $findings = $scan->findings
            ->filter(fn (Finding $finding): bool => ! in_array($finding->category, ['passed', 'scan_diff'], true))
            ->sortByDesc(fn (Finding $finding) => $finding->severity->rank())
            ->values();

        return [
            'scan' => $scan,
            'findings' => $findings,
            'summary' => [
                'high' => $findings->filter(fn (Finding $f) => $f->severity === FindingSeverity::High)->count(),
                'medium' => $findings->filter(fn (Finding $f) => $f->severity === FindingSeverity::Medium)->count(),
                'low' => $findings->filter(fn (Finding $f) => $f->severity === FindingSeverity::Low)->count(),
            ],
            'generatedAt' => now(),
        ];
    }
}
