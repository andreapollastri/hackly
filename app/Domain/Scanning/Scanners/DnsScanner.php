<?php

namespace App\Domain\Scanning\Scanners;

use App\Domain\Scanning\DTO\BinaryResult;
use App\Domain\Scanning\DTO\ScannerFinding;
use App\Enums\AssetType;
use App\Enums\FindingSeverity;
use App\Enums\ScanTaskType;
use App\Models\Asset;
use App\Models\ScanTask;

class DnsScanner extends AbstractScanner
{
    public function type(): ScanTaskType
    {
        return ScanTaskType::DnsInfo;
    }

    public function supports(Asset $asset): bool
    {
        return $asset->type === AssetType::Domain;
    }

    public function timeoutSeconds(): int
    {
        return 60;
    }

    public function buildCommand(Asset $asset, ScanTask $task, string $outputPath): array
    {
        $this->ensureOutputDir($outputPath);

        // Wrapper via shell is avoided; BinaryRunner runs dig once — whois is appended in parse via secondary run.
        return [
            $this->binary('dig'),
            '+noall',
            '+answer',
            $asset->value,
            'ANY',
        ];
    }

    public function parse(Asset $asset, ScanTask $task, BinaryResult $result): array
    {
        $findings = [];
        $records = array_values(array_filter(array_map('trim', explode("\n", $result->stdout))));

        $findings[] = new ScannerFinding(
            title: 'DNS records collected',
            severity: FindingSeverity::Info,
            source: 'dns',
            category: 'dns',
            description: 'DNS ANY/answer records for the domain.',
            evidence: ['records' => array_slice($records, 0, 100)],
            fingerprint: 'dns-records-'.$asset->id,
        );

        $whois = $this->runWhois($asset->value);

        if ($whois !== '') {
            $findings[] = new ScannerFinding(
                title: 'WHOIS information',
                severity: FindingSeverity::Info,
                source: 'dns',
                category: 'whois',
                description: 'Registrar / WHOIS summary.',
                evidence: ['whois' => substr($whois, 0, 4000)],
                fingerprint: 'whois-'.$asset->id,
            );
        }

        return $findings;
    }

    private function runWhois(string $domain): string
    {
        $binary = $this->binary('whois');
        $runner = app(\App\Domain\Scanning\Services\BinaryRunner::class);

        if (! $runner->binaryExists($binary)) {
            return '';
        }

        try {
            $result = $runner->run([$binary, $domain], 30);

            return $result->stdout;
        } catch (\Throwable) {
            return '';
        }
    }
}
