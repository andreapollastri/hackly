<?php

namespace App\Jobs;

use App\Domain\RepoScanning\Services\RepoCloner;
use App\Domain\RepoScanning\Services\RepoScanDispatcher;
use App\Enums\ScanStatus;
use App\Enums\ScanTaskStatus;
use App\Models\RepoScan;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class PrepareRepoScanJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 360;

    public function __construct(public string $repoScanId) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(RepoCloner $cloner, RepoScanDispatcher $dispatcher): void
    {
        $scan = RepoScan::query()->with('repository.credential')->find($this->repoScanId);

        if (! $scan || ! $scan->repository) {
            return;
        }

        $scan->update([
            'status' => ScanStatus::Running,
            'started_at' => $scan->started_at ?? now(),
        ]);

        try {
            $cloner->cloneForScan($scan->repository, $scan);
            $scan->refresh();
            $dispatcher->dispatchTasks($scan);
        } catch (Throwable $e) {
            Log::warning('hackly.repo.prepare.failed', [
                'repo_scan_id' => $scan->id,
                'error' => $e->getMessage(),
            ]);

            $scan->tasks()->update([
                'status' => ScanTaskStatus::Failed,
                'finished_at' => now(),
                'error_message' => 'Clone/prepare failed: '.$e->getMessage(),
            ]);

            $scan->update([
                'status' => ScanStatus::Failed,
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    public function failed(?Throwable $e): void
    {
        $scan = RepoScan::query()->find($this->repoScanId);

        if (! $scan) {
            return;
        }

        $scan->tasks()->whereIn('status', [
            ScanTaskStatus::Pending->value,
            ScanTaskStatus::Queued->value,
        ])->update([
            'status' => ScanTaskStatus::Failed,
            'finished_at' => now(),
            'error_message' => $e?->getMessage() ?: 'Prepare job failed.',
        ]);

        $scan->update([
            'status' => ScanStatus::Failed,
            'finished_at' => now(),
            'error_message' => $e?->getMessage() ?: 'Prepare job failed.',
        ]);
    }
}
