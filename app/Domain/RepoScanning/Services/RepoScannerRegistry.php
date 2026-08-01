<?php

namespace App\Domain\RepoScanning\Services;

use App\Domain\RepoScanning\Contracts\RepoScannerContract;
use App\Enums\RepoScanTaskType;
use InvalidArgumentException;

class RepoScannerRegistry
{
    /** @var array<string, RepoScannerContract> */
    private array $scanners = [];

    /**
     * @param  list<RepoScannerContract>  $scanners
     */
    public function __construct(array $scanners)
    {
        foreach ($scanners as $scanner) {
            $this->scanners[$scanner->type()->value] = $scanner;
        }
    }

    public function get(RepoScanTaskType $type): RepoScannerContract
    {
        if (! isset($this->scanners[$type->value])) {
            throw new InvalidArgumentException("No repo scanner registered for [{$type->value}].");
        }

        return $this->scanners[$type->value];
    }

    /**
     * @return list<RepoScannerContract>
     */
    public function all(): array
    {
        return array_values($this->scanners);
    }
}
