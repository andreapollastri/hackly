<?php

namespace App\Domain\RepoScanning\DTO;

use App\Enums\FindingSeverity;

readonly class RawFinding
{
    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $tools
     */
    public function __construct(
        public string $title,
        public FindingSeverity $severity,
        public string $source,
        public string $category = 'general',
        public ?string $cve = null,
        public ?string $description = null,
        public array $evidence = [],
        public ?string $package = null,
        public ?string $packageVersion = null,
        public ?string $file = null,
        public ?int $line = null,
        public ?string $ruleId = null,
        public array $tools = [],
        public ?string $dedupeKey = null,
    ) {}

    public function resolvedDedupeKey(): string
    {
        if (filled($this->dedupeKey)) {
            return $this->dedupeKey;
        }

        if (filled($this->cve) && filled($this->package)) {
            return 'pkg-cve|'.strtolower((string) $this->package).'|'.strtoupper((string) $this->cve);
        }

        if (filled($this->cve)) {
            return 'cve|'.strtoupper((string) $this->cve);
        }

        if (filled($this->ruleId) && filled($this->file)) {
            return 'rule-file|'.strtolower((string) $this->ruleId).'|'.$this->file.($this->line ? ':'.$this->line : '');
        }

        if (filled($this->ruleId)) {
            return 'rule|'.strtolower((string) $this->ruleId);
        }

        return 'title|'.sha1($this->source.'|'.$this->title.'|'.$this->category.'|'.($this->file ?? ''));
    }
}
