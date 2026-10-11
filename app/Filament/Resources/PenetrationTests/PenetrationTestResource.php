<?php

namespace App\Filament\Resources\PenetrationTests;

use App\Domain\Pentest\PentestAuthorizer;
use App\Domain\Pentest\PentestDispatcher;
use App\Domain\Pentest\PentestScope;
use App\Domain\Scanning\Services\ScannerEgress;
use App\Enums\AssetStatus;
use App\Enums\PentestTechnology;
use App\Filament\Resources\PenetrationTests\Pages\CreatePenetrationTest;
use App\Filament\Resources\PenetrationTests\Pages\EditPenetrationTest;
use App\Filament\Resources\PenetrationTests\Pages\ListPenetrationTests;
use App\Filament\Resources\PenetrationTests\Pages\ViewPenetrationTest;
use App\Filament\Resources\Scans\ScanResource;
use App\Filament\Support\NavigationGroup;
use App\Models\Asset;
use App\Models\PenetrationTest;
use App\Models\Repository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PenetrationTestResource extends Resource
{
    protected static ?string $model = PenetrationTest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBugAnt;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::AttackSurface;

    protected static ?string $navigationLabel = 'Penetration tests';

    protected static ?string $modelLabel = 'penetration test';

    protected static ?string $pluralModelLabel = 'penetration tests';

    protected static ?string $slug = 'penetration-tests';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Target')->schema([
                Select::make('asset_id')
                    ->label('Authorized target')
                    ->options(fn (): array => Asset::query()
                        ->whereNotNull('verified_at')
                        ->where('status', AssetStatus::Active)
                        ->orderBy('value')
                        ->pluck('value', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        $ids = filled($state)
                            ? Asset::query()->find($state)?->repositories()->pluck('repositories.id')->all()
                            : [];
                        $set('repository_ids', $ids ?? []);
                    })
                    ->helperText('The target must already pass the standing DNS ownership check. Each run then asks for a new one.'),
                Select::make('repository_ids')
                    ->label('Linked repositories')
                    ->multiple()
                    ->options(fn (): array => Repository::query()->orderBy('full_name')->pluck('full_name', 'id')->all())
                    ->searchable()
                    ->helperText('The code that runs here. A penetration test also scans these repositories, and DAST checks the routes learned from them.')
                    ->visible(fn (Get $get): bool => filled($get('asset_id'))),
                TextInput::make('label')
                    ->required()
                    ->maxLength(120)
                    ->placeholder('Billing API — October'),
                Select::make('technology')
                    ->options(PentestTechnology::class)
                    ->required()
                    ->native(false),
                Textarea::make('technology_notes')
                    ->label('Stack notes')
                    ->rows(2)
                    ->placeholder('Laravel 13, Livewire, Nginx, Vue storefront')
                    ->maxLength(2000),
            ]),
            Section::make('Scope you want called out')->description('These are extra places to review. The run still covers the whole authorized host.')->schema([
                TextInput::make('swagger_url')
                    ->label('Swagger / OpenAPI URL')
                    ->placeholder('https://api.example.com/openapi.json')
                    ->maxLength(500)
                    ->rule(fn (Get $get): \Closure => self::urlRule($get('asset_id'))),
                TagsInput::make('login_urls')
                    ->label('Login areas')
                    ->placeholder('https://example.com/login')
                    ->rule(fn (Get $get): \Closure => self::urlListRule($get('asset_id'))),
                TagsInput::make('extra_urls')
                    ->label('Other URLs')
                    ->placeholder('https://example.com/admin')
                    ->rule(fn (Get $get): \Closure => self::urlListRule($get('asset_id'))),
                TagsInput::make('ports')
                    ->label('Ports')
                    ->placeholder('8443')
                    ->helperText('Added to the professional port set. Only ports on this domain are scanned.'),
                TextInput::make('test_username')
                    ->label('Test account username')
                    ->maxLength(200)
                    ->helperText('Optional. Used for a single login so the session cookie can be reviewed. Passwords are not guessed.'),
                TextInput::make('test_password')
                    ->label('Test account password')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->maxLength(200)
                    ->helperText('Leave blank to keep the password already stored.'),
            ]),
            Section::make('Context')->schema([
                Textarea::make('notes')->rows(3)->maxLength(5000),
                Textarea::make('authentication_notes')->label('How authentication works')->rows(2)->maxLength(2000),
                Textarea::make('out_of_scope')->label('Out of scope')->rows(2)->maxLength(2000),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Authorization')->schema([
                TextEntry::make('authorization_state')
                    ->label('This run')
                    ->state(fn (PenetrationTest $record): string => $record->authorizationLabel()),
                TextEntry::make('scanner_ip')
                    ->label('Scanner IP')
                    ->state(fn (): string => app(ScannerEgress::class)->address() ?: 'Set HACKLY_EGRESS_IP')
                    ->helperText('Whitelist this address. DAST and penetration tests both leave from it.')
                    ->copyable(),
                TextEntry::make('authorization_host')
                    ->label('TXT host')
                    ->state(fn (PenetrationTest $record): string => (string) $record->asset?->value),
                TextEntry::make('authorization_token')
                    ->label('TXT value')
                    ->placeholder('Request a new authorization to get a token.')
                    ->copyable(),
            ])->columns(2),
            Section::make('Scope')->schema([
                TextEntry::make('asset.value')->label('Target'),
                TextEntry::make('linked_repositories')
                    ->label('Linked repositories')
                    ->state(fn (PenetrationTest $record): array => $record->asset?->repositories()->orderBy('full_name')->pluck('full_name')->all() ?? [])
                    ->placeholder('—')
                    ->listWithLineBreaks(),
                TextEntry::make('technology')->badge(),
                TextEntry::make('technology_notes')->placeholder('—'),
                TextEntry::make('swagger_url')->label('Swagger / OpenAPI')->placeholder('—')->copyable(),
                TextEntry::make('login_urls')->label('Login areas')->placeholder('—')->listWithLineBreaks(),
                TextEntry::make('extra_urls')->label('Other URLs')->placeholder('—')->listWithLineBreaks(),
                TextEntry::make('ports')->label('Declared ports')->placeholder('—')->listWithLineBreaks(),
                TextEntry::make('test_username')->label('Test account')->placeholder('—'),
                TextEntry::make('notes')->placeholder('—'),
                TextEntry::make('authentication_notes')->placeholder('—'),
                TextEntry::make('out_of_scope')->label('Out of scope')->placeholder('—'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->searchable()->sortable(),
                TextColumn::make('asset.value')->label('Target')->searchable(),
                TextColumn::make('technology')->badge(),
                TextColumn::make('authorization')
                    ->state(fn (PenetrationTest $record): string => $record->authorizationLabel()),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->recordUrl(fn (PenetrationTest $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPenetrationTests::route('/'),
            'create' => CreatePenetrationTest::route('/create'),
            'view' => ViewPenetrationTest::route('/{record}'),
            'edit' => EditPenetrationTest::route('/{record}/edit'),
        ];
    }

    public static function issueAction(): Action
    {
        return Action::make('issuePentestAuthorization')
            ->label('New DNS authorization')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Request a new authorization')
            ->modalDescription('Publish the new TXT value, then confirm it. Starting the test consumes this check. The next run needs another one.')
            ->action(function (PenetrationTest $record): void {
                $token = app(PentestAuthorizer::class)->issue($record);
                $ip = app(ScannerEgress::class)->address() ?: 'the address in HACKLY_EGRESS_IP';

                Notification::make()
                    ->title('Publish this TXT record')
                    ->body("Host {$record->asset?->value}. Value {$token}. Whitelist scanner IP {$ip}.")
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    public static function checkAction(): Action
    {
        return Action::make('checkPentestAuthorization')
            ->label('Confirm DNS')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->visible(fn (PenetrationTest $record): bool => filled($record->authorization_token) && $record->authorization_consumed_at === null)
            ->action(function (PenetrationTest $record, Action $action): void {
                try {
                    app(PentestAuthorizer::class)->check($record);
                } catch (\Throwable $e) {
                    Notification::make()->title('TXT record not found yet')->body($e->getMessage())->warning()->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Authorization confirmed')->body('You can start this penetration test. The check is valid for a short window and is consumed when the test starts.')->success()->send();
            });
    }

    public static function startAction(): Action
    {
        return Action::make('startPenetrationTest')
            ->label('Start penetration test')
            ->icon(Heroicon::OutlinedPlay)
            ->color('danger')
            ->visible(fn (PenetrationTest $record): bool => $record->isAuthorizationReady())
            ->requiresConfirmation()
            ->modalHeading('Start this penetration test?')
            ->modalDescription('This authorization is consumed. The run covers the whole authorized host, the linked repositories, and the routes learned from that code.')
            ->modalSubmitActionLabel('Start')
            ->action(function (PenetrationTest $record, Action $action): void {
                try {
                    $scan = app(PentestDispatcher::class)->start($record, auth()->user());
                } catch (\Throwable $e) {
                    Notification::make()->title('Cannot start')->body($e->getMessage())->danger()->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Penetration test queued')->success()->send();
                $action->redirect(ScanResource::getUrl('view', ['record' => $scan]));
            });
    }

    public static function editHeaderAction(): EditAction
    {
        return EditAction::make();
    }

    /**
     * @param  Model|int|string|null  $record
     */
    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var PenetrationTest $record */
        return $record->label;
    }

    private static function urlRule(mixed $assetId): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($assetId): void {
            if (blank($value)) {
                return;
            }

            $asset = Asset::query()->find($assetId);

            if ($asset && PentestScope::normalizeUrl((string) $value, $asset->value) === null) {
                $fail("The URL must stay on {$asset->value}.");
            }
        };
    }

    private static function urlListRule(mixed $assetId): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($assetId): void {
            $asset = Asset::query()->find($assetId);

            if (! $asset) {
                return;
            }

            foreach ((array) $value as $url) {
                if (PentestScope::normalizeUrl((string) $url, $asset->value) === null) {
                    $fail("{$url} is outside {$asset->value}.");
                }
            }
        };
    }
}
