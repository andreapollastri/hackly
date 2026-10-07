<?php

namespace App\Filament\Resources\Assets;

use App\Enums\AssetStatus;
use App\Enums\FindingSeverity;
use App\Filament\Actions\StartTargetScanAction;
use App\Filament\Actions\TargetActions;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\Assets\RelationManagers\FindingsRelationManager;
use App\Filament\Resources\Assets\RelationManagers\RepositoriesRelationManager;
use App\Filament\Resources\Assets\RelationManagers\ScansRelationManager;
use App\Filament\Resources\Scans\ScanResource;
use App\Filament\Support\NavigationGroup;
use App\Models\Asset;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
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

class AssetResource extends Resource
{
    protected static ?string $model = Asset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::AttackSurface;

    protected static ?string $navigationLabel = 'Targets';

    protected static ?string $modelLabel = 'target';

    protected static ?string $pluralModelLabel = 'targets';

    protected static ?string $slug = 'targets';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'value';

    public static function getNavigationBadge(): ?string
    {
        $pending = once(fn (): int => Asset::query()->whereNull('verified_at')->count());

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Targets waiting for DNS verification';
    }

    /**
     * @param  Asset  $record
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Ownership' => $record->isVerified() ? 'Verified' : 'Not verified',
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('value')
                    ->label('Domain')
                    ->placeholder('example.com')
                    ->prefixIcon(Heroicon::OutlinedGlobeAlt)
                    ->required()
                    ->maxLength(253)
                    ->unique(ignoreRecord: true)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('value', static::normalizeDomain((string) $state)))
                    ->dehydrateStateUsing(fn (?string $state): string => static::normalizeDomain((string) $state))
                    ->rule('regex:/^(?!-)(?:[a-z0-9-]{1,63}\.)+[a-z]{2,63}\.?$/i')
                    ->validationMessages([
                        'regex' => 'Enter a domain name such as example.com — no scheme, path or IP address.',
                    ])
                    ->disabled(fn (?Asset $record): bool => (bool) $record?->isVerified())
                    ->helperText(fn (?Asset $record): string => $record?->isVerified()
                        ? 'Locked after verification. Create a new target to scan a different domain.'
                        : 'A domain or subdomain you control. Its A/AAAA addresses are resolved and checked at scan time.'),
                Textarea::make('authorization_note')
                    ->label('Authorization reference')
                    ->placeholder('e.g. Pentest agreement #2026-14, owner: security@example.com')
                    ->rows(2)
                    ->maxLength(1000)
                    ->helperText('Optional. Where the permission to test this target is documented.'),
            ]);
    }

    public static function normalizeDomain(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value);
        $value = explode('/', $value, 2)[0];
        $value = explode('?', $value, 2)[0];
        $value = (string) preg_replace('/:\d+$/', '', $value);

        return rtrim($value, '.');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make('Verify ownership to unlock scanning')
                    ->description('Hackly only scans domains you prove you control, by publishing a DNS TXT record.')
                    ->icon(Heroicon::OutlinedShieldExclamation)
                    ->iconColor('warning')
                    ->columnSpanFull()
                    ->visible(fn (Asset $record): bool => ! $record->isVerified())
                    ->headerActions([
                        TargetActions::verify()->label('Check DNS'),
                    ])
                    ->schema([
                        ViewEntry::make('verification')
                            ->hiddenLabel()
                            ->view('filament.targets.verification-entry'),
                    ]),
                Section::make('Overview')
                    ->icon(Heroicon::OutlinedGlobeAlt)
                    ->columnSpan(['lg' => 2])
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextEntry::make('verified_at')
                            ->label('Ownership')
                            ->badge()
                            ->state(fn (Asset $record): string => $record->isVerified() ? 'Verified' : 'Not verified')
                            ->color(fn (Asset $record): string => $record->isVerified() ? 'success' : 'warning')
                            ->icon(fn (Asset $record): Heroicon => $record->isVerified() ? Heroicon::OutlinedShieldCheck : Heroicon::OutlinedShieldExclamation)
                            ->helperText(fn (Asset $record): ?string => $record->verified_at ? 'via DNS TXT · '.$record->verified_at->diffForHumans() : null),
                        TextEntry::make('status')
                            ->badge()
                            ->helperText(fn (Asset $record): ?string => $record->isActive() ? null : 'Paused targets are kept but you should not scan them.'),
                        TextEntry::make('latestScan.created_at')
                            ->label('Last scan')
                            ->since()
                            ->dateTimeTooltip()
                            ->placeholder('Never scanned')
                            ->url(fn (Asset $record): ?string => $record->latestScan ? ScanResource::getUrl('view', ['record' => $record->latestScan]) : null),
                        TextEntry::make('latestScan.status')
                            ->label('Last result')
                            ->badge()
                            ->placeholder('—'),
                        TextEntry::make('authorization_note')
                            ->label('Authorization reference')
                            ->placeholder('Not documented')
                            ->columnSpanFull(),
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
                ->with('latestScan')
                ->withCount([
                    'repositories',
                    'findings as high_findings_count' => fn ($q) => $q->issues()->open()->where('severity', FindingSeverity::High),
                    'findings as medium_findings_count' => fn ($q) => $q->issues()->open()->where('severity', FindingSeverity::Medium),
                    'findings as low_findings_count' => fn ($q) => $q->issues()->open()->where('severity', FindingSeverity::Low),
                ]))
            ->defaultSort('value')
            ->recordUrl(fn (Asset $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('value')
                    ->label('Domain')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (Asset $record): ?string => $record->repositories_count > 0
                        ? $record->repositories_count.' linked '.str('repository')->plural($record->repositories_count)
                        : null),
                TextColumn::make('verified_at')
                    ->label('Ownership')
                    ->badge()
                    ->state(fn (Asset $record): string => $record->isVerified() ? 'Verified' : 'Pending')
                    ->color(fn (Asset $record): string => $record->isVerified() ? 'success' : 'warning')
                    ->icon(fn (Asset $record): Heroicon => $record->isVerified() ? Heroicon::OutlinedShieldCheck : Heroicon::OutlinedShieldExclamation)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                ViewColumn::make('open_issues')
                    ->label('Open issues')
                    ->view('filament.tables.columns.scan-findings-summary')
                    ->state(fn (Asset $record): ?array => $record->latestScan === null ? null : [
                        'high' => (int) $record->high_findings_count,
                        'medium' => (int) $record->medium_findings_count,
                        'low' => (int) $record->low_findings_count,
                    ]),
                TextColumn::make('latestScan.created_at')
                    ->label('Last scan')
                    ->since()
                    ->dateTimeTooltip()
                    ->placeholder('Never')
                    ->color('gray'),
            ])
            ->filters([
                TernaryFilter::make('verified_at')
                    ->label('Ownership')
                    ->nullable()
                    ->trueLabel('Verified')
                    ->falseLabel('Pending verification'),
                SelectFilter::make('status')
                    ->options(AssetStatus::class),
            ])
            ->recordActions([
                TargetActions::verify()->button()->size('sm'),
                StartTargetScanAction::make()
                    ->button()
                    ->size('sm')
                    ->outlined()
                    ->visible(fn (Asset $record): bool => $record->isVerified()),
                ActionGroup::make([
                    EditAction::make(),
                    TargetActions::toggleStatus(),
                    TargetActions::regenerateToken(),
                    DeleteAction::make(),
                ])->tooltip('More'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedGlobeAlt)
            ->emptyStateActions([ListAssets::createAction()])
            ->emptyStateHeading('Add your first target')
            ->emptyStateDescription('A target is a domain you own. After a quick DNS ownership check you can run attack-surface scans against it.');
    }

    public static function getRelations(): array
    {
        return [
            FindingsRelationManager::class,
            ScansRelationManager::class,
            RepositoriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssets::route('/'),
            'view' => ViewAsset::route('/{record}'),
            'edit' => EditAsset::route('/{record}/edit'),
        ];
    }
}
