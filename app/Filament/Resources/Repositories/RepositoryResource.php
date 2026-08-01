<?php

namespace App\Filament\Resources\Repositories;

use App\Domain\RepoScanning\Services\GithubClient;
use App\Domain\RepoScanning\Services\RepoScanDispatcher;
use App\Enums\ScanProfile;
use App\Enums\ScanTaskStatus;
use App\Filament\Resources\Repositories\Pages\EditRepository;
use App\Filament\Resources\Repositories\Pages\ListRepositories;
use App\Filament\Resources\Repositories\Pages\ViewRepository;
use App\Filament\Resources\Repositories\RelationManagers\AssetsRelationManager;
use App\Filament\Resources\Repositories\RelationManagers\FindingsRelationManager;
use App\Filament\Resources\Repositories\RelationManagers\ScansRelationManager;
use App\Models\GithubCredential;
use App\Models\Repository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class RepositoryResource extends Resource
{
    protected static ?string $model = Repository::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracketSquare;

    protected static ?string $navigationLabel = 'Repositories';

    protected static ?string $modelLabel = 'Repository';

    protected static ?string $pluralModelLabel = 'Repositories';

    protected static ?string $slug = 'repositories';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Select::make('github_credential_id')
                    ->label('GitHub token')
                    ->relationship('credential', 'name')
                    ->required()
                    ->native(false)
                    ->helperText('Token needs Contents: Read + Metadata: Read (fine-grained) or repo/public_repo (classic).'),
                TextInput::make('full_name')
                    ->label('Repository')
                    ->required()
                    ->placeholder('owner/repo')
                    ->helperText('GitHub owner/name')
                    ->disabled(fn (?Repository $record): bool => $record !== null)
                    ->dehydrated(),
                TextInput::make('default_branch')
                    ->label('Default branch')
                    ->maxLength(100)
                    ->placeholder('main'),
                Toggle::make('nightly_enabled')
                    ->label('Nightly scan')
                    ->default(true),
                Select::make('assets')
                    ->label('Linked targets')
                    ->relationship('assets', 'value')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->native(false)
                    ->helperText('Optional. Link Targets to combine repo scans with DAST / live Laravel probes when you choose “Include linked targets”.'),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('full_name')->label('Repository')->size('lg')->weight('bold'),
            TextEntry::make('default_branch')->badge(),
            TextEntry::make('status')->badge(),
            TextEntry::make('is_private')
                ->label('Visibility')
                ->formatStateUsing(fn (bool $state): string => $state ? 'Private' : 'Public'),
            TextEntry::make('nightly_enabled')->badge()
                ->formatStateUsing(fn (bool $state): string => $state ? 'Nightly on' : 'Nightly off')
                ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
            TextEntry::make('last_scanned_at')->dateTime()->placeholder('Never'),
            TextEntry::make('last_commit_sha')->label('Last commit')->placeholder('—')->copyable(),
            TextEntry::make('html_url')->label('GitHub')->url(fn (?string $state) => $state)->placeholder('—'),
            TextEntry::make('credential.name')->label('Token'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Repository $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('full_name')->searchable()->sortable()->weight('medium'),
                TextColumn::make('default_branch')->badge(),
                IconColumn::make('is_private')->boolean()->label('Private'),
                ToggleColumn::make('nightly_enabled')->label('Nightly'),
                TextColumn::make('assets_count')->counts('assets')->label('Targets'),
                TextColumn::make('last_scanned_at')->since()->placeholder('Never')->label('Last scan'),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([
                Action::make('startRepoScan')
                    ->label('Scan now')
                    ->icon(Heroicon::OutlinedPlay)
                    ->color('primary')
                    ->form([
                        Select::make('profile')
                            ->options([
                                ScanProfile::Quick->value => 'Quick — Composer/OSV + secrets + Laravel audit',
                                ScanProfile::Standard->value => 'Standard — + Semgrep, Trivy',
                                ScanProfile::Deep->value => 'Deep — + Checkov IaC',
                            ])
                            ->default(ScanProfile::Standard->value)
                            ->required()
                            ->native(false),
                        Toggle::make('include_targets')
                            ->label('Include linked targets')
                            ->helperText('Also deep-scan every verified Target linked to this repository (DAST + live Laravel probes).')
                            ->default(false)
                            ->visible(fn (Repository $record): bool => $record->assets()->exists()),
                    ])
                    ->action(function (Repository $record, array $data) {
                        try {
                            $includeTargets = (bool) ($data['include_targets'] ?? false);
                            $result = app(RepoScanDispatcher::class)->createScan(
                                $record,
                                ScanProfile::from($data['profile']),
                                auth()->user(),
                                includeLinkedTargets: $includeTargets,
                                linkedTargetProfile: ScanProfile::Deep,
                            );

                            $scan = $result['scan'];
                            $queued = $scan->tasks->where('status', ScanTaskStatus::Pending)->count()
                                + $scan->tasks->where('status', ScanTaskStatus::Queued)->count();
                            $targetCount = count($result['linked_target_scans']);

                            Notification::make()
                                ->title("Repo scan {$scan->id} started")
                                ->body($targetCount > 0
                                    ? "Clone queued ({$queued} repo task(s)) + {$targetCount} linked target deep scan(s)."
                                    : "Clone queued, then {$queued} scanner task(s).")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Cannot start repo scan')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            AssetsRelationManager::class,
            ScansRelationManager::class,
            FindingsRelationManager::class,
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
        $fullName = str_replace('https://github.com/', '', $fullName);
        $fullName = trim($fullName, '/');

        if (str_ends_with(strtolower($fullName), '.git')) {
            $fullName = substr($fullName, 0, -4);
        }

        if (! str_contains($fullName, '/')) {
            throw new \InvalidArgumentException('Repository must be in owner/name format.');
        }

        [$owner, $name] = explode('/', $fullName, 2);
        $owner = trim($owner);
        $name = trim($name);

        $credential = GithubCredential::query()->find($data['github_credential_id'] ?? null);

        if (! $credential) {
            throw new \InvalidArgumentException('Select a GitHub token.');
        }

        $remote = app(GithubClient::class)->fetchRepository($credential, $owner, $name);

        $data['owner'] = $owner;
        $data['name'] = $name;
        $data['full_name'] = $owner.'/'.$name;
        $data['default_branch'] = $data['default_branch'] ?: (string) ($remote['default_branch'] ?? 'main');
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
