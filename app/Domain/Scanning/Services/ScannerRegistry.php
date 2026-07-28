<?php

namespace App\Domain\Scanning\Services;

use App\Domain\Scanning\Contracts\ScannerContract;
use App\Enums\ScanTaskType;
use InvalidArgumentException;

class ScannerRegistry
{
    /** @var array<string, ScannerContract> */
    private array $scanners = [];

    /**
     * @param  iterable<ScannerContract>  $scanners
     */
    public function __construct(iterable $scanners = [])
    {
        foreach ($scanners as $scanner) {
            $this->register($scanner);
        }
    }

    public function register(ScannerContract $scanner): void
    {
        $this->scanners[$scanner->type()->value] = $scanner;
    }

    public function get(ScanTaskType|string $type): ScannerContract
    {
        $key = $type instanceof ScanTaskType ? $type->value : $type;

        if (! isset($this->scanners[$key])) {
            throw new InvalidArgumentException("No scanner registered for type [{$key}].");
        }

        return $this->scanners[$key];
    }

    /**
     * @return array<string, ScannerContract>
     */
    public function all(): array
    {
        return $this->scanners;
    }
}
