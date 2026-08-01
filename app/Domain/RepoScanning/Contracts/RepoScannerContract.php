<?php

namespace App\Domain\RepoScanning\Contracts;

use App\Domain\RepoScanning\DTO\RawFinding;
use App\Domain\Scanning\DTO\BinaryResult;
use App\Enums\RepoScanTaskType;
use App\Models\RepoScan;
use App\Models\RepoScanTask;
use App\Models\Repository;

interface RepoScannerContract
{
    public function type(): RepoScanTaskType;

    public function supports(Repository $repository, RepoScan $scan): bool;

    /**
     * @return list<string>
     */
    public function buildCommand(Repository $repository, RepoScan $scan, RepoScanTask $task, string $outputPath): array;

    public function timeoutSeconds(): int;

    public function queue(): string;

    /**
     * @return array<string, string>
     */
    public function processEnvironment(Repository $repository, RepoScan $scan): array;

    public function workingDirectory(RepoScan $scan): ?string;

    /**
     * Optional in-process scan (no external binary). Return null to use buildCommand + BinaryRunner.
     *
     * @return list<RawFinding>|null
     */
    public function runInProcess(Repository $repository, RepoScan $scan, RepoScanTask $task): ?array;

    /**
     * @return list<RawFinding>
     */
    public function parse(Repository $repository, RepoScan $scan, RepoScanTask $task, BinaryResult $result): array;
}
