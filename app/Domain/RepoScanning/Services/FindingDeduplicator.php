<?php

namespace App\Domain\RepoScanning\Services;

use App\Domain\RepoScanning\DTO\RawFinding;
use App\Enums\FindingSeverity;

class FindingDeduplicator
{
    /**
     * Merge findings that describe the same issue across tools.
     *
     * @param  list<RawFinding>  $findings
     * @return list<RawFinding>
     */
    public function dedupe(array $findings): array
    {
        /** @var array<string, RawFinding> $merged */
        $merged = [];

        foreach ($findings as $finding) {
            $key = $finding->resolvedDedupeKey();

            if (! isset($merged[$key])) {
                $merged[$key] = $finding;

                continue;
            }

            $merged[$key] = $this->merge($merged[$key], $finding);
        }

        return array_values($merged);
    }

    private function merge(RawFinding $a, RawFinding $b): RawFinding
    {
        $severity = $a->severity->rank() >= $b->severity->rank() ? $a->severity : $b->severity;
        $tools = array_values(array_unique(array_merge(
            $a->tools !== [] ? $a->tools : [$a->source],
            $b->tools !== [] ? $b->tools : [$b->source],
        )));

        $evidence = array_merge($a->evidence, $b->evidence, [
            'tools' => $tools,
            'dedupe_key' => $a->resolvedDedupeKey(),
            'merged_sources' => array_values(array_unique([$a->source, $b->source, ...($a->evidence['merged_sources'] ?? []), ...($b->evidence['merged_sources'] ?? [])])),
        ]);

        return new RawFinding(
            title: $a->title,
            severity: $severity instanceof FindingSeverity ? $severity : FindingSeverity::normalize((string) $severity),
            source: 'hackly-repo',
            category: $a->category !== 'general' ? $a->category : $b->category,
            cve: $a->cve ?? $b->cve,
            description: $this->pickDescription($a, $b),
            evidence: $evidence,
            package: $a->package ?? $b->package,
            packageVersion: $a->packageVersion ?? $b->packageVersion,
            file: $a->file ?? $b->file,
            line: $a->line ?? $b->line,
            ruleId: $a->ruleId ?? $b->ruleId,
            tools: $tools,
            dedupeKey: $a->resolvedDedupeKey(),
        );
    }

    private function pickDescription(RawFinding $a, RawFinding $b): ?string
    {
        $aLen = strlen((string) $a->description);
        $bLen = strlen((string) $b->description);

        return $aLen >= $bLen ? $a->description : $b->description;
    }
}
