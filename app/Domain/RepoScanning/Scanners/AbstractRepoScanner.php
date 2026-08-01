<?php

namespace App\Domain\RepoScanning\Scanners;

use App\Domain\RepoScanning\Contracts\RepoScannerContract;
use App\Domain\Scanning\Services\BinaryRunner;
use App\Enums\RepoScanTaskType;
use App\Models\RepoScan;
use App\Models\RepoScanTask;
use App\Models\Repository;

abstract class AbstractRepoScanner implements RepoScannerContract
{
    abstract public function type(): RepoScanTaskType;

    public function queue(): string
    {
        return (string) config('hackly.repo.queues.'.$this->type()->value, 'default');
    }

    public function supports(Repository $repository, RepoScan $scan): bool
    {
        return true;
    }

    protected function binaryAvailable(string $name): bool
    {
        return app(BinaryRunner::class)->binaryExists($this->binary($name));
    }

    /**
     * @return array<string, string>
     */
    public function processEnvironment(Repository $repository, RepoScan $scan): array
    {
        return [];
    }

    public function workingDirectory(RepoScan $scan): ?string
    {
        return $scan->workspace_path;
    }

    public function runInProcess(Repository $repository, RepoScan $scan, RepoScanTask $task): ?array
    {
        return null;
    }

    protected function binary(string $name): string
    {
        return (string) config("hackly.binaries.{$name}");
    }

    protected function ensureOutputDir(string $outputPath): void
    {
        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    protected function workspace(RepoScan $scan): string
    {
        $path = $scan->workspace_path;

        if (! is_string($path) || $path === '' || ! is_dir($path)) {
            throw new \RuntimeException('Repository workspace is not available. Clone step may have failed.');
        }

        return $path;
    }
}
