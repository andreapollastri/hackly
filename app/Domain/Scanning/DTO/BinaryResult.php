<?php

namespace App\Domain\Scanning\DTO;

readonly class BinaryResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public ?string $outputPath = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }
}
