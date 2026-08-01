<?php

namespace App\Filament\Resources\Assets;

use App\Domain\Scanning\Services\DnsOwnershipVerifier;
use App\Domain\Scanning\Services\ScanDispatcher;
use App\Enums\AssetStatus;
use App\Enums\ScanProfile;
use App\Enums\ScanTaskStatus;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\Assets\RelationManagers\RepositoriesRelationManager;
use App\Filament\Resources\Assets\RelationManagers\ScansRelationManager;
use App\Models\Asset;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AssetResource extends Resource
{
    protected static ?string $model = Asset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Targets';

    protected static ?string $modelLabel = 'Target';

    protected static ?string $pluralModelLabel = 'Targets';

    protected static ?string $slug = 'targets';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('value')
                    ->label('Domain')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->disabled(fn (?Asset $record): bool => (bool) $record?->isVerified())
                    ->helperText(fn (?Asset $record): ?string => $record?->isVerified()
                        ? 'Locked after verification. Create a new target to scan a different domain.'
                        : 'FQDN only (e.g. example.com). Resolved A/AAAA IPs are checked and scanned with the domain.'),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('value')->label('Domain')->size('lg')->weight('bold'),
            TextEntry::make('status')->badge(),
            TextEntry::make('verified_at')
                ->label('DNS verified')
                ->dateTime()
                ->placeholder('Not verified')
                ->color(fn ($state) => $state ? 'success' : 'danger'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(fn (Asset $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('value')
                    ->label('Domain')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                IconColumn::make('verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->getStateUsing(fn (Asset $record) => $record->isVerified())
                    ->trueIcon(Heroicon::OutlinedShieldCheck)
                    ->falseIcon(Heroicon::OutlinedShieldExclamation)
                    ->trueColor('success')
                    ->falseColor('danger'),
                ToggleColumn::make('status')
                    ->label('Active')
                    ->onColor('success')
                    ->offColor('gray')
                    ->getStateUsing(fn (Asset $record): bool => $record->status === AssetStatus::Active)
                    ->updateStateUsing(function (Asset $record, mixed $state): bool {
                        $record->update([
                            'status' => $state ? AssetStatus::Active : AssetStatus::Paused,
                        ]);

                        return (bool) $state;
                    }),
                TextColumn::make('scans_count')
                    ->counts('scans')
                    ->label('Scans'),
                TextColumn::make('updated_at')->since()->label('Updated'),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    AssetStatus::Active->value => 'Active',
                    AssetStatus::Paused->value => 'Disabled',
                ]),
            ])
            ->recordActions([
                Action::make('issueToken')
                    ->label('DNS token')
                    ->icon(Heroicon::OutlinedKey)
                    ->color('gray')
                    ->visible(fn (Asset $record) => ! $record->isVerified())
                    ->modalHeading('Publish this TXT record')
                    ->modalDescription('Add this DNS TXT record at your registrar or DNS provider. Wait for propagation, then click Verify DNS.')
                    ->modalWidth(Width::Medium)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Done')
                    ->mountUsing(function (Action $action, ?Schema $schema, Asset $record): void {
                        try {
                            $token = app(DnsOwnershipVerifier::class)->issueToken($record);

                            $schema?->fill([
                                'host' => $record->value,
                                'type' => 'TXT',
                                'value' => $token,
                            ]);
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Cannot issue token')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            $action->halt();
                        }
                    })
                    ->form([
                        TextInput::make('host')
                            ->label('Host')
                            ->readOnly()
                            ->copyable(),
                        TextInput::make('type')
                            ->label('Type')
                            ->readOnly()
                            ->copyable(),
                        TextInput::make('value')
                            ->label('Value')
                            ->readOnly()
                            ->copyable(),
                    ]),
                Action::make('verifyDns')
                    ->label('Verify DNS')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->color('success')
                    ->visible(fn (Asset $record) => ! $record->isVerified())
                    ->action(function (Asset $record) {
                        try {
                            app(DnsOwnershipVerifier::class)->verify($record);

                            Notification::make()
                                ->title('Target verified')
                                ->body('DNS TXT ownership confirmed. You can start scans.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Verification failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),
                Action::make('startScan')
                    ->label('Start scan')
                    ->icon(Heroicon::OutlinedPlay)
                    ->color('primary')
                    ->disabled(fn (Asset $record) => ! $record->isVerified())
                    ->tooltip(fn (Asset $record) => $record->isVerified()
                        ? 'Dispatch scan jobs now'
                        : 'Verify DNS ownership first')
                    ->form([
                        Select::make('profile')
                            ->options([
                                ScanProfile::Quick->value => 'Quick — DNS + mail + ports',
                                ScanProfile::Standard->value => 'Standard — + subdomains, paths, Nuclei',
                                ScanProfile::Deep->value => 'Deep — + ZAP baseline',
                            ])
                            ->default(ScanProfile::Standard->value)
                            ->required()
                            ->native(false),
                        Toggle::make('include_repos')
                            ->label('Include linked repositories')
                            ->helperText('Also run repo SAST/SCA scans for every GitHub repo linked to this target.')
                            ->default(false)
                            ->visible(fn (Asset $record): bool => $record->repositories()->exists()),
                    ])
                    ->action(function (Asset $record, array $data) {
                        try {
                            $result = app(ScanDispatcher::class)->createScan(
                                $record,
                                ScanProfile::from($data['profile']),
                                auth()->user(),
                                includeLinkedRepos: (bool) ($data['include_repos'] ?? false),
                            );

                            $scan = $result['scan'];
                            $queued = $scan->tasks->where('status', ScanTaskStatus::Queued)->count();
                            $repoCount = count($result['linked_repo_scans']);

                            Notification::make()
                                ->title("Scan {$scan->id} started")
                                ->body($repoCount > 0
                                    ? "{$queued} target task(s) + {$repoCount} linked repo scan(s) queued."
                                    : "{$queued} task(s) dispatched to the queue. Watch progress under Scans.")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Cannot start scan')
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
            RepositoriesRelationManager::class,
            ScansRelationManager::class,
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
