<?php

namespace App\Filament\Resources\Repositories;

use App\Domain\RepoScanning\Services\GithubClient;
use App\Enums\FindingSeverity;
use App\Filament\Actions\StartRepositoryScanAction;
use App\Filament\Resources\GithubCredentials\GithubCredentialResource;
use App\Filament\Resources\RepoScans\RepoScanResource;
use App\Filament\Resources\Repositories\Pages\EditRepository;
use App\Filament\Resources\Repositories\Pages\ListRepositories;
use App\Filament\Resources\Repositories\Pages\ViewRepository;
use App\Filament\Resources\Repositories\RelationManagers\AssetsRelationManager;
use App\Filament\Resources\Repositories\RelationManagers\FindingsRelationManager;
use App\Filament\Resources\Repositories\RelationManagers\ScansRelationManager;
use App\Filament\Support\NavigationGroup;
use App\Models\GithubCredential;
use App\Models\Repository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class RepositoryResource extends Resource
{
    protected static ?string $model = Repository::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracketSquare;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::AttackSurface;

    protected static ?string $navigationLabel = 'Repositories';

    protected static ?string $modelLabel = 'repository';

    protected static ?string $pluralModelLabel = 'repositories';

    protected static ?string $slug = 'repositories';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'full_name';

    /**
     * @param  Repository  $record
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'Branch' => $record->default_branch,
            'Language' => $record->language(),
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('full_name')
                    ->label('Repository')
                    ->required()
                    ->placeholder('owner/repo or https://github.com/owner/repo')
                    ->prefixIcon(Heroicon::OutlinedCodeBracketSquare)
                    ->helperText('Metadata (visibility, default branch, language) is fetched from GitHub when you save.')
                    ->disabled(fn (?Repository $record): bool => $record !== null)
                    ->dehydrated(fn (?Repository $record): bool => $record === null),
                Select::make('github_credential_id')
                    ->label('GitHub token')
                    ->relationship('credential', 'name')
                    ->getOptionLabelFromRecordUsing(fn (GithubCredential $record): string => $record->name.($record->token_hint ? " ({$record->token_hint})" : ''))
                    ->default(fn (): ?string => GithubCredential::query()->count() === 1 ? GithubCredential::query()->value('id') : null)
                    ->required()
                    ->preload()
                    ->native(false)
                    ->createOptionForm(GithubCredentialResource::formComponents())
                    ->createOptionUsing(fn (array $data): string => GithubCredentialResource::createCredential($data)->getKey())
                    ->createOptionModalHeading('Add a GitHub token')
                    ->helperText('Needs read access to the repository: fine-grained “Contents: Read” + “Metadata: Read”, or classic “repo”.'),
                TextInput::make('default_branch')
                    ->label('Branch to scan')
                    ->maxLength(100)
                    ->placeholder('Repository default')
                    ->prefixIcon(Heroicon::OutlinedArrowsRightLeft),
                Select::make('assets')
                    ->label('Linked targets')
                    ->relationship('assets', 'value')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->native(false)
                    ->helperText('Optional. Link the domains this code runs on to combine SAST with live DAST probes.'),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make('Overview')
                    ->icon(Heroicon::OutlinedCodeBracketSquare)
                    ->columnSpan(['lg' => 2])
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextEntry::make('description')
                            ->hiddenLabel()
                            ->state(fn (Repository $record): ?string => $record->description())
                            ->placeholder('No description on GitHub.')
                            ->color('gray')
                            ->columnSpanFull(),
                        TextEntry::make('is_private')
                            ->label('Visibility')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Private' : 'Public')
                            ->icon(fn (bool $state): Heroicon => $state ? Heroicon::OutlinedLockClosed : Heroicon::OutlinedGlobeAlt)
                            ->color(fn (bool $state): string => $state ? 'gray' : 'info'),
                        TextEntry::make('default_branch')
                            ->label('Branch')
                            ->badge()
                            ->color('gray')
                            ->icon(Heroicon::OutlinedArrowsRightLeft),
                        TextEntry::make('language')
                            ->state(fn (Repository $record): ?string => $record->language())
                            ->placeholder('—'),
                        TextEntry::make('credential.name')
                            ->label('GitHub token')
                            ->icon(Heroicon::OutlinedKey)
                            ->url(fn (): string => GithubCredentialResource::getUrl('index')),
                        TextEntry::make('last_scanned_at')
                            ->label('Last scan')
                            ->since()
                            ->dateTimeTooltip()
                            ->placeholder('Never scanned')
                            ->url(fn (Repository $record): ?string => $record->latestScan ? RepoScanResource::getUrl('view', ['record' => $record->latestScan]) : null),
                        TextEntry::make('last_commit_sha')
                            ->label('Last commit')
                            ->fontFamily('mono')
                            ->limit(10, end: '')
                            ->copyable()
                            ->placeholder('—'),
                    ]),
                Section::make('Open issues')
                    ->icon(Heroicon::OutlinedBugAnt)
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        ViewEntry::make('open_issues')
                            ->hiddenLabel()
                            ->view('filament.partials.open-issues-entry'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['latestScan', 'credential'])
                ->withCount([
                    'assets',
                    'findings as high_findings_count' => fn ($q) => $q->issues()->open()->where('severity', FindingSeverity::High),
                    'findings as medium_findings_count' => fn ($q) => $q->issues()->open()->where('severity', FindingSeverity::Medium),
                    'findings as low_findings_count' => fn ($q) => $q->issues()->open()->where('severity', FindingSeverity::Low),
                ]))
            ->defaultSort('full_name')
            ->recordUrl(fn (Repository $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('full_name')
                    ->label('Repository')
                    ->searchable(['full_name', 'owner', 'name'])
                    ->sortable()
                    ->weight('semibold')
                    ->icon(fn (Repository $record): Heroicon => $record->is_private ? Heroicon::OutlinedLockClosed : Heroicon::OutlinedCodeBracketSquare)
                    ->iconColor('gray')
                    ->description(fn (Repository $record): ?string => $record->description()
                        ? str($record->description())->limit(70)->toString()
                        : null),
                TextColumn::make('language')
                    ->state(fn (Repository $record): ?string => $record->language())
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('default_branch')
                    ->label('Branch')
                    ->fontFamily('mono')
                    ->color('gray')
                    ->toggleable(),
                ViewColumn::make('open_issues')
                    ->label('Open issues')
                    ->view('filament.tables.columns.scan-findings-summary')
                    ->state(fn (Repository $record): ?array => $record->latestScan === null ? null : [
                        'high' => (int) $record->high_findings_count,
                        'medium' => (int) $record->medium_findings_count,
                        'low' => (int) $record->low_findings_count,
                    ]),
                TextColumn::make('assets_count')
                    ->label('Targets')
                    ->numeric()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('last_scanned_at')
                    ->label('Last scan')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('Never')
                    ->sortable()
                    ->color('gray'),
            ])
            ->filters([
                TernaryFilter::make('is_private')
                    ->label('Visibility')
                    ->trueLabel('Private')
                    ->falseLabel('Public'),
                SelectFilter::make('github_credential_id')
                    ->label('Token')
                    ->relationship('credential', 'name'),
            ])
            ->recordActions([
                StartRepositoryScanAction::make()
                    ->button()
                    ->size('sm')
                    ->outlined(),
                ActionGroup::make([
                    static::openOnGithubAction(),
                    EditAction::make(),
                    DeleteAction::make(),
                ])->tooltip('More'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedCodeBracketSquare)
            ->emptyStateActions([ListRepositories::createAction()])
            ->emptyStateHeading('Add a GitHub repository')
            ->emptyStateDescription('Hackly clones it with a read-only token and runs Semgrep, Trivy, Gitleaks, Composer audit and Laravel-specific checks.');
    }

    public static function openOnGithubAction(): Action
    {
        return Action::make('openOnGithub')
            ->label('Open on GitHub')
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->url(fn (Repository $record): string => $record->html_url ?: 'https://github.com/'.$record->full_name, shouldOpenInNewTab: true);
    }

    public static function getRelations(): array
    {
        return [
            FindingsRelationManager::class,
            ScansRelationManager::class,
            AssetsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRepositories::route('/'),
            'view' => ViewRepository::route('/{record}'),
            'edit' => EditRepository::route('/{record}/edit'),
        ];
    }

    /**
     * Resolve owner/name + GitHub metadata when creating.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function hydrateFromGithub(array $data): array
    {
        $fullName = trim((string) ($data['full_name'] ?? ''));
        $fullName = (string) preg_replace('#^(https?://)?(www\.)?github\.com/#i', '', $fullName);
        $fullName = (string) preg_replace('#^git@github\.com:#i', '', $fullName);
        $fullName = trim($fullName, '/');

        if (str_ends_with(strtolower($fullName), '.git')) {
            $fullName = substr($fullName, 0, -4);
        }

        if (! preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $fullName)) {
            throw new \InvalidArgumentException('Repository must look like owner/name (or a github.com URL).');
        }

        [$owner, $name] = explode('/', $fullName, 2);

        if (Repository::query()->where('full_name', $owner.'/'.$name)->exists()) {
            throw new \InvalidArgumentException("{$owner}/{$name} is already registered.");
        }

        $credential = GithubCredential::query()->find($data['github_credential_id'] ?? null);

        if (! $credential) {
            throw new \InvalidArgumentException('Select a GitHub token.');
        }

        $remote = app(GithubClient::class)->fetchRepository($credential, $owner, $name);

        $data['owner'] = $owner;
        $data['name'] = $name;
        $data['full_name'] = $owner.'/'.$name;
        $data['default_branch'] = ($data['default_branch'] ?? null) ?: (string) ($remote['default_branch'] ?? 'main');
        $data['is_private'] = (bool) ($remote['private'] ?? true);
        $data['html_url'] = (string) ($remote['html_url'] ?? "https://github.com/{$owner}/{$name}");
        $data['status'] = 'active';
        $data['created_by'] = auth()->id();
        $data['meta'] = [
            'description' => $remote['description'] ?? null,
            'language' => $remote['language'] ?? null,
            'topics' => $remote['topics'] ?? [],
        ];

        return $data;
    }
}
