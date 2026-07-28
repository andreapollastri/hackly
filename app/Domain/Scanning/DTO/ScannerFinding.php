<?php

namespace App\Domain\Scanning\DTO;

use App\Enums\FindingSeverity;

readonly class ScannerFinding
{
    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public string $title,
        public FindingSeverity $severity,
        public string $source,
        public string $category = 'general',
        public ?string $cve = null,
        public ?string $description = null,
        public array $evidence = [],
        public ?string $fingerprint = null,
    ) {}

    public function resolvedFingerprint(string $assetId): string
    {
        return $this->fingerprint ?? sha1($assetId.'|'.$this->source.'|'.$this->title.'|'.$this->category);
    }
}
