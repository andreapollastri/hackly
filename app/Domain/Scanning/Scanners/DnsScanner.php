<?php

namespace App\Domain\Scanning\Scanners;

use App\Domain\Scanning\DTO\BinaryResult;
use App\Domain\Scanning\DTO\ScannerFinding;
use App\Domain\Scanning\Services\BinaryRunner;
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
            severity: FindingSeverity::Low,
            source: 'dns',
            category: 'dns',
            description: 'DNS ANY/answer records for the domain.',
            evidence: ['records' => array_slice($records, 0, 100)],
            fingerprint: 'dns-records-'.$asset->id,
        );

        $whois = $this->runWhois($asset->value);

        if ($whois !== '') {
            $cleaned = $this->cleanWhois($whois);
            $summary = $this->summarizeWhois($cleaned);

            $findings[] = new ScannerFinding(
                title: 'WHOIS information',
                severity: FindingSeverity::Low,
                source: 'dns',
                category: 'whois',
                description: $this->whoisDescription($summary),
                evidence: array_filter([
                    'registrar' => $summary['registrar'] ?? null,
                    'created' => $summary['created'] ?? null,
                    'expires' => $summary['expires'] ?? null,
                    'status' => $summary['status'] ?? null,
                    'dnssec' => $summary['dnssec'] ?? null,
                    'nameservers' => $summary['nameservers'] ?? null,
                    'whois' => substr($cleaned, 0, 4000),
                ], fn ($v) => $v !== null && $v !== '' && $v !== []),
                fingerprint: 'whois-'.$asset->id,
            );
        }

        return $findings;
    }

    private function runWhois(string $domain): string
    {
        $binary = $this->binary('whois');
        $runner = app(BinaryRunner::class);

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

    /**
     * Drop IANA / TLD referral preamble so evidence keeps only the domain record.
     */
    private function cleanWhois(string $raw): string
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        // Client followed a referral: keep the referred server response only.
        if (preg_match('/^#\s*whois\.\S+\s*$/m', $raw, $match, PREG_OFFSET_CAPTURE)) {
            $raw = substr($raw, $match[0][1]);
            $raw = preg_replace('/^#\s*whois\.\S+\s*\n+/', '', $raw, 1) ?? $raw;
        }

        // Strip nic.it / registry banner blocks.
        $raw = preg_replace('/^\*{10,}[\s\S]*?\*{10,}\s*/m', '', $raw) ?? $raw;

        // Drop leading IANA-only comment/header lines if still present.
        $raw = preg_replace('/^(?:%.*\n)+\n*/', '', $raw) ?? $raw;

        return trim($raw);
    }

    /**
     * @return array{registrar?: string, created?: string, expires?: string, status?: string, dnssec?: string, nameservers?: list<string>}
     */
    private function summarizeWhois(string $whois): array
    {
        $summary = [];

        $map = [
            'registrar' => [
                '/^[ \t]*Registrar Organization:\s*(.+)$/mi',
                '/^[ \t]*Registrar:\s*(.+)$/mi',
            ],
            'created' => [
                '/^[ \t]*Creat(?:ed|ion Date|ed On):\s*(.+)$/mi',
                '/^[ \t]*Created Date:\s*(.+)$/mi',
            ],
            'expires' => [
                '/^[ \t]*Expir(?:e Date|y Date|ation Date):\s*(.+)$/mi',
                '/^[ \t]*Registry Expiry Date:\s*(.+)$/mi',
            ],
            'status' => [
                '/^[ \t]*(?:Domain )?Status:\s*(.+)$/mi',
            ],
            'dnssec' => [
                '/^[ \t]*DNSSEC:\s*(.+)$/mi',
                '/^[ \t]*Signed:\s*(.+)$/mi',
            ],
        ];

        foreach ($map as $key => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $whois, $m)) {
                    $value = trim($m[1]);
                    if ($value !== '' && strtolower($value) !== 'hidden') {
                        $summary[$key] = $value;
                        break;
                    }
                }
            }
        }

        // nic.it nested "Registrar / Organization:" block
        if (! isset($summary['registrar']) && preg_match('/^Registrar\s*\n\s+Organization:\s*(.+)$/mi', $whois, $m)) {
            $summary['registrar'] = trim($m[1]);
        }

        $nameservers = [];
        if (preg_match_all('/^Name Server:\s*(.+)$/mi', $whois, $matches)) {
            $nameservers = $matches[1];
        } elseif (preg_match('/^Nameservers\s*\n((?:[ \t]+\S+[ \t]*\n?)*)/mi', $whois, $block)) {
            preg_match_all('/^[ \t]+(\S+)[ \t]*$/m', $block[1], $ns);
            $nameservers = $ns[1] ?? [];
        } elseif (preg_match_all('/^nserver:\s*(\S+)/mi', $whois, $matches)) {
            $nameservers = $matches[1];
        }

        $nameservers = array_values(array_unique(array_map(
            fn (string $ns) => strtolower(trim($ns)),
            $nameservers,
        )));

        if ($nameservers !== []) {
            $summary['nameservers'] = $nameservers;
        }

        return $summary;
    }

    /**
     * @param  array{registrar?: string, created?: string, expires?: string, status?: string, dnssec?: string, nameservers?: list<string>}  $summary
     */
    private function whoisDescription(array $summary): string
    {
        $parts = [];

        if (isset($summary['registrar'])) {
            $parts[] = 'Registrar: '.$summary['registrar'];
        }
        if (isset($summary['expires'])) {
            $parts[] = 'Expires: '.$summary['expires'];
        }
        if (isset($summary['status'])) {
            $parts[] = 'Status: '.$summary['status'];
        }

        return $parts !== []
            ? implode(' · ', $parts)
            : 'Registrar / WHOIS summary.';
    }
}
