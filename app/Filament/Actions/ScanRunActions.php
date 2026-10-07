<?php

namespace App\Filament\Actions;

use App\Domain\RepoScanning\Services\RepoScanDispatcher;
use App\Domain\Scanning\Services\ScanDispatcher;
use App\Filament\Resources\RepoScans\RepoScanResource;
use App\Filament\Resources\Scans\ScanResource;
use App\Models\RepoScan;
use App\Models\Scan;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cancel / re-run / export actions shared by target scans and repository scans.
 */
class ScanRunActions
{
    public static function cancel(): Action
    {
        return Action::make('cancelScan')
            ->label('Cancel scan')
            ->icon(Heroicon::OutlinedStopCircle)
            ->color('danger')
            ->visible(fn (Scan|RepoScan $record): bool => $record->canBeCancelled())
            ->requiresConfirmation()
            ->modalHeading('Cancel this scan?')
            ->modalDescription('Tasks that have not started yet are skipped. A task that is already running finishes its current tool run.')
            ->modalSubmitActionLabel('Cancel scan')
            ->modalCancelActionLabel('Keep running')
            ->action(fn (Scan|RepoScan $record) => $record->cancel())
            ->successNotificationTitle('Scan cancelled');
    }

    public static function rerun(): Action
    {
        return Action::make('rerunScan')
            ->label('Run again')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (Scan|RepoScan $record): bool => ! $record->isActive())
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedArrowPath)
            ->modalHeading(fn (Scan|RepoScan $record): string => 'Run the '.$record->profile->getLabel().' profile again?')
            ->modalDescription(fn (Scan|RepoScan $record): string => 'A new scan of '.$record->subjectName().' starts with the same profile.')
            ->modalSubmitActionLabel('Start scan')
            ->action(function (Scan|RepoScan $record, Action $action): void {
                try {
                    if ($record instanceof Scan) {
                        $scan = app(ScanDispatcher::class)->createScan($record->asset, $record->profile, auth()->user())['scan'];
                        $url = ScanResource::getUrl('view', ['record' => $scan]);
                    } else {
                        $scan = app(RepoScanDispatcher::class)->createScan($record->repository, $record->profile, auth()->user())['scan'];
                        $url = RepoScanResource::getUrl('view', ['record' => $scan]);
                    }
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

                Notification::make()->title('New scan started')->success()->send();

                $action->redirect($url);
            });
    }

    public static function exportPdf(): Action
    {
        return Action::make('exportPdf')
            ->label('PDF report')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->action(fn (Scan|RepoScan $record): StreamedResponse => $record instanceof Scan
                ? ScanResource::downloadReport($record)
                : RepoScanResource::downloadReport($record));
    }

    public static function exportMarkdown(): Action
    {
        return Action::make('exportMarkdown')
            ->label('Markdown report')
            ->icon(Heroicon::OutlinedDocumentText)
            ->action(fn (Scan|RepoScan $record): StreamedResponse => $record instanceof Scan
                ? ScanResource::downloadMarkdownReport($record)
                : RepoScanResource::downloadMarkdownReport($record));
    }

    public static function export(): ActionGroup
    {
        return ActionGroup::make([
            static::exportPdf(),
            static::exportMarkdown(),
        ])
            ->label('Export')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->button();
    }

    /**
     * Compact "⋯" menu for table rows.
     */
    public static function rowMenu(): ActionGroup
    {
        return ActionGroup::make([
            static::rerun(),
            static::exportPdf(),
            static::exportMarkdown(),
            static::cancel(),
        ])->tooltip('More');
    }
}
