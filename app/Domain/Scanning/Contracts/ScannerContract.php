<?php

namespace App\Domain\Scanning\Contracts;

use App\Domain\Scanning\DTO\BinaryResult;
use App\Domain\Scanning\DTO\ScannerFinding;
use App\Enums\ScanTaskType;
use App\Models\Asset;
use App\Models\ScanTask;

interface ScannerContract
{
    public function type(): ScanTaskType;

    public function supports(Asset $asset): bool;

    /**
     * @return list<string>
     */
    public function buildCommand(Asset $asset, ScanTask $task, string $outputPath): array;

    public function timeoutSeconds(): int;

    public function estimateCost(): int;

    public function queue(): string;

    /**
     * Extra environment variables for the process (e.g. HOME for Nuclei).
     *
     * @return array<string, string>
     */
    public function processEnvironment(): array;

    /**
     * Optional working directory for the process.
     */
    public function workingDirectory(): ?string;

    /**
     * @return list<ScannerFinding>
     */
    public function parse(Asset $asset, ScanTask $task, BinaryResult $result): array;
}
