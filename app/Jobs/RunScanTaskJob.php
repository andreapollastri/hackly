<?php

namespace App\Jobs;

use App\Domain\Scanning\Services\BinaryRunner;
use App\Domain\Scanning\Services\ScanDispatcher;
use App\Domain\Scanning\Services\ScannerRegistry;
use App\Domain\Scanning\Services\TargetGuard;
use App\Enums\ScanTaskStatus;
use App\Models\ScanTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunScanTaskJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Queue worker kill timeout; must be a property (timeout() is ignored by Laravel). */
    public int $timeout;

    public function __construct(public string $scanTaskId)
    {
        $this->timeout = (int) config('hackly.queue.job_timeout', 960);
    }

    public function backoff(): array
    {
        return [60, 180, 600];
    }

    public function handle(
        ScannerRegistry $registry,
        BinaryRunner $runner,
        ScanDispatcher $dispatcher,
        TargetGuard $guard,
    ): void {
        $task = ScanTask::query()->with('scan.asset')->find($this->scanTaskId);

        if (! $task) {
            return;
        }

        if ($task->status === ScanTaskStatus::Completed) {
            return;
        }

        $asset = $task->scan?->asset;

        if (! $asset) {
            $task->update([
                'status' => ScanTaskStatus::Failed,
                'error_message' => 'Missing asset.',
                'finished_at' => now(),
            ]);

            return;
        }

        try {
            $guard->assertAllowed($asset);
        } catch (Throwable $e) {
            $task->update([
                'status' => ScanTaskStatus::Failed,
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            $task->scan?->refreshStatusFromTasks();

            return;
        }

        $scanner = $registry->get($task->type);

        $outputDir = rtrim((string) config('hackly.storage_path'), '/').'/'.$task->scan_id;
        $outputPath = $outputDir.'/'.$task->id.'_'.$task->type->value;

        $task->update([
            'status' => ScanTaskStatus::Running,
            'started_at' => now(),
            'attempts' => $task->attempts + 1,
            'raw_output_path' => $outputPath,
        ]);

        $task->scan?->refreshStatusFromTasks();

        try {
            $command = $scanner->buildCommand($asset, $task, $outputPath);
            $result = $runner->run($command, $scanner->timeoutSeconds(), $outputPath);
            $findings = $scanner->parse($asset, $task, $result);
            $dispatcher->persistFindings($task, $findings);

            $task->update([
                'status' => ScanTaskStatus::Completed,
                'finished_at' => now(),
                'error_message' => null,
                'meta' => [
                    'exit_code' => $result->exitCode,
                    'findings_count' => count($findings),
                ],
            ]);
        } catch (Throwable $e) {
            Log::warning('hackly.task.failed', [
                'task_id' => $task->id,
                'type' => $task->type->value,
                'error' => $e->getMessage(),
            ]);

            $message = $e->getMessage();
            $retryable = str_contains(strtolower($message), '429')
                || str_contains(strtolower($message), 'timed out')
                || str_contains(strtolower($message), 'connection refused');

            if ($retryable && $this->attempts() < $this->tries) {
                $task->update([
                    'status' => ScanTaskStatus::Pending,
                    'scheduled_at' => now()->addSeconds(60 * $this->attempts()),
                    'error_message' => $message,
                ]);

                throw $e;
            }

            $task->update([
                'status' => ScanTaskStatus::Failed,
                'finished_at' => now(),
                'error_message' => $message,
            ]);
        }

        $task->scan?->refreshStatusFromTasks();
    }

    public function failed(?Throwable $e): void
    {
        $task = ScanTask::query()->with('scan')->find($this->scanTaskId);

        if (! $task || in_array($task->status, [ScanTaskStatus::Completed, ScanTaskStatus::Failed, ScanTaskStatus::Skipped], true)) {
            return;
        }

        $message = $e?->getMessage() ?: 'Queue job failed.';

        Log::warning('hackly.task.failed', [
            'task_id' => $task->id,
            'type' => $task->type->value,
            'error' => $message,
        ]);

        $task->update([
            'status' => ScanTaskStatus::Failed,
            'finished_at' => now(),
            'error_message' => $message,
        ]);

        $task->scan?->refreshStatusFromTasks();
    }
}
