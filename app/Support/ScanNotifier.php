<?php

namespace App\Support;

use App\Enums\ScanStatus;
use App\Filament\Resources\RepoScans\RepoScanResource;
use App\Filament\Resources\Scans\ScanResource;
use App\Models\RepoScan;
use App\Models\Scan;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Sends an in-app (database) notification to whoever started a scan once it finishes.
 */
class ScanNotifier
{
    public static function finished(Scan|RepoScan $scan): void
    {
        try {
            $user = $scan->requester()->first();

            if (! $user || ! Schema::hasTable('notifications')) {
                return;
            }

            $summary = $scan->findingsSeveritySummary();
            $subject = $scan->subjectName();
            $isRepo = $scan instanceof RepoScan;
            $url = $isRepo
                ? RepoScanResource::getUrl('view', ['record' => $scan], panel: 'admin')
                : ScanResource::getUrl('view', ['record' => $scan], panel: 'admin');

            $notification = Notification::make()
                ->title($scan->status === ScanStatus::Completed
                    ? "Scan of {$subject} completed"
                    : "Scan of {$subject} failed")
                ->actions([
                    Action::make('view')
                        ->label('View results')
                        ->button()
                        ->url($url)
                        ->markAsRead(),
                ]);

            if ($scan->status === ScanStatus::Completed) {
                $notification
                    ->body(sprintf('%d high · %d medium · %d low', $summary['high'], $summary['medium'], $summary['low']))
                    ->icon($summary['high'] > 0 ? 'heroicon-o-fire' : 'heroicon-o-check-circle')
                    ->iconColor($summary['high'] > 0 ? 'danger' : 'success');
            } else {
                $notification
                    ->body($scan->error_message ?: 'All tasks failed. Open the scan to see each task error.')
                    ->danger();
            }

            $notification->sendToDatabase($user);
        } catch (Throwable $e) {
            Log::warning('hackly.scan.notify_failed', ['scan_id' => $scan->getKey(), 'error' => $e->getMessage()]);
        }
    }
}
