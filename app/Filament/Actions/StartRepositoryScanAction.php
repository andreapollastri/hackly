<?php

namespace App\Filament\Actions;

use App\Domain\RepoScanning\Services\RepoScanDispatcher;
use App\Enums\ScanProfile;
use App\Enums\ScanTaskStatus;
use App\Filament\Resources\RepoScans\RepoScanResource;
use App\Models\Repository;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * "Scan now" for a GitHub repository (record = Repository), or a global variant with a repository picker.
 */
class StartRepositoryScanAction
{
    public static function make(string $name = 'startRepoScan', bool $pickRepository = false): Action
    {
        return Action::make($name)
            ->label($pickRepository ? 'Scan a repository' : 'Scan now')
            ->icon(Heroicon::OutlinedPlay)
            ->color('primary')
            ->modalHeading(fn (?Repository $record): string => $pickRepository || ! $record ? 'Start a repository scan' : "Scan {$record->full_name}")
            ->modalDescription('The repository is shallow-cloned with its GitHub token, scanned, then the clone is removed.')
            ->modalIcon(Heroicon::OutlinedCodeBracket)
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Start scan')
            ->schema([
                Select::make('repository_id')
                    ->label('Repository')
                    ->options(fn (): array => Repository::query()
                        ->where('status', 'active')
                        ->orderBy('full_name')
                        ->pluck('full_name', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->live()
                    ->visible($pickRepository),
                Radio::make('profile')
                    ->label('Profile')
                    ->options(ScanProfile::class)
                    ->descriptions(collect(ScanProfile::cases())
                        ->mapWithKeys(fn (ScanProfile $p): array => [$p->value => $p->repositoryDescription()])
                        ->all())
                    ->default(ScanProfile::Standard->value)
                    ->required(),
                Toggle::make('include_targets')
                    ->label('Also deep-scan linked targets')
                    ->helperText('Queues a deep DAST scan and live Laravel probes on every verified target linked to this repository.')
                    ->default(false)
                    ->visible(fn (?Repository $record, Get $get): bool => (static::resolveRepository($record, $get('repository_id')))?->assets()->exists() ?? false),
            ])
            ->action(function (?Repository $record, array $data, Action $action): void {
                $repository = static::resolveRepository($record, $data['repository_id'] ?? null);

                if (! $repository) {
                    $action->failure();

                    return;
                }

                try {
                    $result = app(RepoScanDispatcher::class)->createScan(
                        $repository,
                        ScanProfile::from($data['profile']),
                        auth()->user(),
                        includeLinkedTargets: (bool) ($data['include_targets'] ?? false),
                        linkedTargetProfile: ScanProfile::Deep,
                    );
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Cannot start repository scan')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    $action->halt();

                    return;
                }

                $scan = $result['scan'];
                $queued = $scan->tasks->whereIn('status', [ScanTaskStatus::Pending, ScanTaskStatus::Queued])->count();
                $targetCount = count($result['linked_target_scans']);

                Notification::make()
                    ->title("Scan of {$repository->full_name} started")
                    ->body($targetCount > 0
                        ? "Clone queued, then {$queued} tasks — plus {$targetCount} linked target scan(s)."
                        : "Clone queued, then {$queued} scanner tasks.")
                    ->success()
                    ->send();

                $action->redirect(RepoScanResource::getUrl('view', ['record' => $scan]));
            });
    }

    protected static function resolveRepository(?Repository $record, mixed $repositoryId): ?Repository
    {
        if ($record instanceof Repository) {
            return $record;
        }

        return filled($repositoryId) ? Repository::query()->find($repositoryId) : null;
    }
}
