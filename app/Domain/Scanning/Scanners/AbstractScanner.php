<?php

namespace App\Domain\Scanning\Scanners;

use App\Domain\Scanning\Contracts\ScannerContract;
use App\Enums\ScanTaskType;
use App\Models\Asset;
use App\Models\ScanTask;

abstract class AbstractScanner implements ScannerContract
{
    abstract public function type(): ScanTaskType;

    public function queue(): string
    {
        return (string) config('hackly.queues.'.$this->type()->value, 'default');
    }

    public function estimateCost(): int
    {
        return 1;
    }

    public function supports(Asset $asset): bool
    {
        return true;
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

    /**
     * @return list<string>
     */
    abstract public function buildCommand(Asset $asset, ScanTask $task, string $outputPath): array;
}
