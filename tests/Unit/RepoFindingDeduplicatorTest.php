<?php

namespace Tests\Unit;

use App\Domain\RepoScanning\DTO\RawFinding;
use App\Domain\RepoScanning\Services\FindingDeduplicator;
use App\Enums\FindingSeverity;
use PHPUnit\Framework\TestCase;

class RepoFindingDeduplicatorTest extends TestCase
{
    public function test_merges_same_cve_across_tools(): void
    {
        $deduper = new FindingDeduplicator;

        $merged = $deduper->dedupe([
            new RawFinding(
                title: 'CVE in package (foo/bar)',
                severity: FindingSeverity::Medium,
                source: 'trivy',
                category: 'sca',
                cve: 'CVE-2024-1234',
                package: 'foo/bar',
                tools: ['trivy'],
            ),
            new RawFinding(
                title: 'OSV hit (foo/bar)',
                severity: FindingSeverity::High,
                source: 'osv',
                category: 'sca',
                cve: 'CVE-2024-1234',
                package: 'foo/bar',
                tools: ['osv'],
            ),
        ]);

        $this->assertCount(1, $merged);
        $this->assertSame(FindingSeverity::High, $merged[0]->severity);
        $this->assertEqualsCanonicalizing(['trivy', 'osv'], $merged[0]->tools);
    }
}
