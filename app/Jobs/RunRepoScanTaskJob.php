<?php

namespace App\Jobs;

use App\Domain\RepoScanning\Services\RepoScanDispatcher;
use App\Domain\RepoScanning\Services\RepoScannerRegistry;
use App\Domain\Scanning\Services\BinaryRunner;
use App\Enums\ScanTaskStatus;
use App\Models\RepoScanTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunRepoScanTaskJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout;

    public function __construct(public string $repoScanTaskId)
    {
        $this->timeout = (int) config('hackly.repo.job_timeout', 960);
    }

    public function backoff(): array
    {
        return [60, 180];
    }

    public function handle(
        RepoScannerRegistry $registry,
        BinaryRunner $runner,
        RepoScanDispatcher $dispatcher,
    ): void {
        $task = RepoScanTask::query()->with('scan.repository')->find($this->repoScanTaskId);

        if (! $task) {
            return;
        }

        if ($task->status === ScanTaskStatus::Completed) {
            return;
        }

        $scan = $task->scan;
        $repository = $scan?->repository;

        if (! $scan || ! $repository) {
            $task->update([
                'status' => ScanTaskStatus::Failed,
                'error_message' => 'Missing repository scan context.',
                'finished_at' => now(),
            ]);

            return;
        }

        $scanner = $registry->get($task->type);

        if (! $scanner->supports($repository, $scan)) {
            $task->update([
                'status' => ScanTaskStatus::Skipped,
                'finished_at' => now(),
                'error_message' => 'Scanner not applicable.',
            ]);
            $scan->refreshStatusFromTasks();

            return;
        }

        $outputDir = rtrim((string) config('hackly.repo.storage_path', storage_path('app/repo-scan-outputs')), '/').'/'.$scan->id;
        $outputPath = $outputDir.'/'.$task->id.'_'.$task->type->value;

        $task->update([
            'status' => ScanTaskStatus::Running,
            'started_at' => now(),
            'attempts' => $task->attempts + 1,
            'raw_output_path' => $outputPath,
        ]);

        $scan->refreshStatusFromTasks();

        try {
            $inProcess = $scanner->runInProcess($repository, $scan, $task);

            if ($inProcess !== null) {
                $findings = $inProcess;
                $exitCode = 0;
            } else {
                $command = $scanner->buildCommand($repository, $scan, $task, $outputPath);
                $result = $runner->run(
                    $command,
                    $scanner->timeoutSeconds(),
                    $outputPath,
                    $scanner->processEnvironment($repository, $scan) ?: null,
                    $scanner->workingDirectory($scan),
                );
                $findings = $scanner->parse($repository, $scan, $task, $result);
                $exitCode = $result->exitCode;
            }

            $dispatcher->persistTaskFindings($task, $findings);

            $task->update([
                'status' => ScanTaskStatus::Completed,
                'finished_at' => now(),
                'error_message' => null,
                'meta' => [
                    'exit_code' => $exitCode,
                    'findings_count' => count($findings),
                ],
            ]);
        } catch (Throwable $e) {
            Log::warning('hackly.repo.task.failed', [
                'task_id' => $task->id,
                'type' => $task->type->value,
                'error' => $e->getMessage(),
            ]);

            $message = $e->getMessage();
            $retryable = str_contains(strtolower($message), 'timed out')
                || str_contains(strtolower($message), '429');

            if ($retryable && $this->attempts() < $this->tries) {
                $task->update([
                    'status' => ScanTaskStatus::Pending,
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
        $task = RepoScanTask::query()->with('scan')->find($this->repoScanTaskId);

        if (! $task || in_array($task->status, [ScanTaskStatus::Completed, ScanTaskStatus::Failed, ScanTaskStatus::Skipped], true)) {
            return;
        }

        $task->update([
            'status' => ScanTaskStatus::Failed,
            'finished_at' => now(),
            'error_message' => $e?->getMessage() ?: 'Queue job failed.',
        ]);

        $task->scan?->refreshStatusFromTasks();
    }
}
