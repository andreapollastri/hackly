<?php

namespace App\Filament\Actions;

use App\Domain\Scanning\Services\ScanDispatcher;
use App\Enums\ScanProfile;
use App\Enums\ScanTaskStatus;
use App\Filament\Resources\Scans\ScanResource;
use App\Models\Asset;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * "Start scan" for a target. Used on table rows / view pages (record = Asset)
 * and as a global action where the target is picked in the form.
 */
class StartTargetScanAction
{
    public static function make(string $name = 'startScan', bool $pickTarget = false): Action
    {
        return Action::make($name)
            ->label($pickTarget ? 'Scan a target' : 'Start scan')
            ->icon(Heroicon::OutlinedPlay)
            ->color('primary')
            ->modalHeading(fn (?Asset $record): string => $pickTarget || ! $record ? 'Start a target scan' : "Scan {$record->value}")
            ->modalDescription('Tasks are queued immediately and throttled by the soft rate limits. You can follow progress live.')
            ->modalIcon(Heroicon::OutlinedPlay)
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Start scan')
            ->disabled(fn (?Asset $record): bool => ! $pickTarget && $record !== null && ! $record->isVerified())
            ->tooltip(fn (?Asset $record): ?string => ! $pickTarget && $record !== null && ! $record->isVerified()
                ? 'Verify DNS ownership first'
                : null)
            ->schema([
                Select::make('asset_id')
                    ->label('Target')
                    ->options(fn (): array => Asset::query()
                        ->whereNotNull('verified_at')
                        ->orderBy('value')
                        ->pluck('value', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->live()
                    ->helperText('Only DNS-verified targets can be scanned.')
                    ->visible($pickTarget),
                Radio::make('profile')
                    ->label('Profile')
                    ->options(ScanProfile::class)
                    ->default(ScanProfile::Standard->value)
                    ->required(),
                Toggle::make('include_repos')
                    ->label('Also scan linked repositories')
                    ->helperText('Runs SAST / SCA / secrets on every GitHub repository linked to this target.')
                    ->default(false)
                    ->visible(fn (?Asset $record, Get $get): bool => (static::resolveAsset($record, $get('asset_id')))?->repositories()->exists() ?? false),
            ])
            ->action(function (?Asset $record, array $data, Action $action): void {
                $asset = static::resolveAsset($record, $data['asset_id'] ?? null);

                if (! $asset) {
                    $action->failure();

                    return;
                }

                try {
                    $result = app(ScanDispatcher::class)->createScan(
                        $asset,
                        ScanProfile::from($data['profile']),
                        auth()->user(),
                        includeLinkedRepos: (bool) ($data['include_repos'] ?? false),
                    );
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Cannot start scan')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    $action->halt();

                    return;
                }

                $scan = $result['scan'];
                $queued = $scan->tasks->whereIn('status', [ScanTaskStatus::Queued, ScanTaskStatus::Pending])->count();
                $repoCount = count($result['linked_repo_scans']);

                Notification::make()
                    ->title("Scan of {$asset->value} started")
                    ->body($repoCount > 0
                        ? "{$queued} tasks queued, plus {$repoCount} linked repository scan(s)."
                        : "{$queued} tasks queued. Make sure a queue worker is running.")
                    ->success()
                    ->send();

                $action->redirect(ScanResource::getUrl('view', ['record' => $scan]));
            });
    }

    protected static function resolveAsset(?Asset $record, mixed $assetId): ?Asset
    {
        if ($record instanceof Asset) {
            return $record;
        }

        return filled($assetId) ? Asset::query()->find($assetId) : null;
    }
}
