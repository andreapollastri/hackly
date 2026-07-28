<?php

namespace App\Filament\Resources\Assets;

use App\Domain\Scanning\Services\DnsOwnershipVerifier;
use App\Domain\Scanning\Services\ScanDispatcher;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ScanProfile;
use App\Enums\ScanTaskStatus;
use App\Filament\Resources\Assets\Pages\EditAsset;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\Assets\RelationManagers\ScansRelationManager;
use App\Models\Asset;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
                Select::make('type')
                    ->label('Type')
                    ->options(collect(AssetType::cases())->mapWithKeys(fn ($c) => [$c->value => strtoupper($c->value)]))
                    ->required()
                    ->native(false),
                TextInput::make('value')
                    ->label('Domain / IP')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('value')->label('Target')->size('lg')->weight('bold'),
            TextEntry::make('type')->badge(),
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
                    ->label('Target')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Asset $record) => strtoupper($record->type->value)),
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
                SelectFilter::make('type')->options(collect(AssetType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value])),
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
                    ->visible(fn (Asset $record) => $record->isDomain() && ! $record->isVerified())
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
                                ->body($record->isDomain()
                                    ? 'DNS TXT ownership confirmed. You can start scans.'
                                    : 'IP ownership confirmed via authorization note.')
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
                                ScanProfile::Quick->value => 'Quick — DNS + ports',
                                ScanProfile::Standard->value => 'Standard — + subdomains, paths, Nuclei',
                                ScanProfile::Deep->value => 'Deep — + ZAP baseline',
                            ])
                            ->default(ScanProfile::Standard->value)
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (Asset $record, array $data) {
                        try {
                            $scan = app(ScanDispatcher::class)->createScan(
                                $record,
                                ScanProfile::from($data['profile']),
                                auth()->user(),
                            );

                            $queued = $scan->tasks->where('status', ScanTaskStatus::Queued)->count();

                            Notification::make()
                                ->title("Scan {$scan->id} started")
                                ->body("{$queued} task(s) dispatched to the queue. Watch progress under Scans.")
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
