<?php

namespace App\Filament\Resources\Repositories\Pages;

use App\Domain\RepoScanning\Services\RepoScanDispatcher;
use App\Enums\ScanProfile;
use App\Enums\ScanTaskStatus;
use App\Filament\Resources\Repositories\RepositoryResource;
use App\Models\Repository;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewRepository extends ViewRecord
{
    protected static string $resource = RepositoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startRepoScan')
                ->label('Scan now')
                ->icon(Heroicon::OutlinedPlay)
                ->color('primary')
                ->form([
                    Select::make('profile')
                        ->options([
                            ScanProfile::Quick->value => 'Quick',
                            ScanProfile::Standard->value => 'Standard',
                            ScanProfile::Deep->value => 'Deep',
                        ])
                        ->default(ScanProfile::Standard->value)
                        ->required()
                        ->native(false),
                    Toggle::make('include_targets')
                        ->label('Include linked targets')
                        ->helperText('Also deep-scan every verified Target linked to this repository.')
                        ->default(false)
                        ->visible(fn (): bool => $this->getRecord()->assets()->exists()),
                ])
                ->action(function (array $data): void {
                    /** @var Repository $record */
                    $record = $this->getRecord();

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
                        $queued = $scan->tasks->whereIn('status', [ScanTaskStatus::Pending, ScanTaskStatus::Queued])->count();
                        $targetCount = count($result['linked_target_scans']);

                        Notification::make()
                            ->title("Repo scan {$scan->id} started")
                            ->body($targetCount > 0
                                ? "{$queued} repo task(s) + {$targetCount} linked target deep scan(s)."
                                : "{$queued} task(s) will run after clone.")
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
            EditAction::make(),
        ];
    }
}
