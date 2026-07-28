<?php

namespace App\Domain\Scanning\Scanners;

use App\Domain\Scanning\DTO\BinaryResult;
use App\Domain\Scanning\DTO\ScannerFinding;
use App\Domain\Scanning\Services\BinaryRunner;
use App\Domain\Scanning\Services\TargetGuard;
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

        $resolvedIps = app(TargetGuard::class)->resolvePublicFacingIps($asset->value);

        if ($resolvedIps !== []) {
            $findings[] = new ScannerFinding(
                title: 'Resolved IP addresses',
                severity: FindingSeverity::Low,
                source: 'dns',
                category: 'ip',
                description: 'A/AAAA addresses included with this domain: '.implode(', ', $resolvedIps),
                evidence: [
                    'domain' => $asset->value,
                    'ips' => $resolvedIps,
                ],
                fingerprint: 'dns-ips-'.$asset->id,
            );
        }

        $whois = $this->runWhois($asset->value);
        $summary = [];

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

        $findings = [
            ...$findings,
            ...$this->analyzeDnssec($asset, $summary),
            ...$this->analyzeCaa($asset),
            ...$this->analyzeAxfr($asset, $summary),
            ...$this->analyzeWildcard($asset),
        ];

        return $findings;
    }

    /**
     * @param  array{dnssec?: string}  $summary
     * @return list<ScannerFinding>
     */
    private function analyzeDnssec(Asset $asset, array $summary): array
    {
        $dnssec = strtolower((string) ($summary['dnssec'] ?? ''));
        $signed = $dnssec !== '' && ! in_array($dnssec, ['unsigned', 'no', 'false', 'disabled'], true);

        // Also probe DS at the parent via dig when WHOIS is silent.
        if (! $signed) {
            $ds = $this->digShort($asset->value, 'DS');
            $signed = $ds !== [];
            if ($signed) {
                $dnssec = 'signed (DS present)';
            }
        }

        if ($signed) {
            return [
                new ScannerFinding(
                    title: 'DNSSEC is enabled',
                    severity: FindingSeverity::Low,
                    source: 'dns',
                    category: 'passed',
                    description: 'DNSSEC appears enabled ('.$dnssec.').',
                    evidence: ['dnssec' => $dnssec],
                    fingerprint: 'dns-dnssec-ok-'.$asset->id,
                ),
            ];
        }

        return [
            new ScannerFinding(
                title: 'DNSSEC not enabled',
                severity: FindingSeverity::Low,
                source: 'dns',
                category: 'dnssec',
                description: 'DNSSEC is unsigned/absent. Enabling it protects against forged DNS responses for this zone.',
                evidence: ['dnssec' => $dnssec !== '' ? $dnssec : 'unsigned'],
                fingerprint: 'dns-dnssec-missing-'.$asset->id,
            ),
        ];
    }

    /**
     * @return list<ScannerFinding>
     */
    private function analyzeCaa(Asset $asset): array
    {
        $caa = $this->digShort($asset->value, 'CAA');

        if ($caa === []) {
            return [
                new ScannerFinding(
                    title: 'CAA record missing',
                    severity: FindingSeverity::Low,
                    source: 'dns',
                    category: 'caa',
                    description: 'No CAA records. Any publicly trusted CA may issue certificates for this domain. Publish CAA to restrict issuers.',
                    evidence: ['domain' => $asset->value],
                    fingerprint: 'dns-caa-missing-'.$asset->id,
                ),
            ];
        }

        return [
            new ScannerFinding(
                title: 'CAA records present',
                severity: FindingSeverity::Low,
                source: 'dns',
                category: 'passed',
                description: 'CAA restricts certificate issuance: '.implode('; ', array_slice($caa, 0, 5)),
                evidence: ['caa' => $caa],
                fingerprint: 'dns-caa-ok-'.$asset->id,
            ),
        ];
    }

    /**
     * @param  array{nameservers?: list<string>}  $summary
     * @return list<ScannerFinding>
     */
    private function analyzeAxfr(Asset $asset, array $summary): array
    {
        $nameservers = $summary['nameservers'] ?? $this->digShort($asset->value, 'NS');

        if ($nameservers === []) {
            return [];
        }

        $runner = app(BinaryRunner::class);
        $dig = $this->binary('dig');
        $open = [];

        foreach (array_slice($nameservers, 0, 4) as $ns) {
            $ns = strtolower(rtrim(trim($ns), '.'));
            if ($ns === '') {
                continue;
            }

            try {
                $result = $runner->run([$dig, 'AXFR', $asset->value, '@'.$ns], 12);
            } catch (\Throwable) {
                continue;
            }

            $out = $result->stdout.$result->stderr;
            $lower = strtolower($out);

            if (
                str_contains($lower, 'transfer failed')
                || str_contains($lower, 'refused')
                || str_contains($lower, 'not authoritative')
                || str_contains($lower, 'connection timed out')
                || trim($result->stdout) === ''
            ) {
                continue;
            }

            // A successful AXFR typically returns many RR lines including SOA twice.
            $lines = array_values(array_filter(array_map('trim', explode("\n", $result->stdout))));
            $soaCount = count(array_filter($lines, static fn (string $l): bool => str_contains(strtoupper($l), 'SOA')));

            if (count($lines) >= 5 && $soaCount >= 1) {
                $open[] = $ns;
            }
        }

        if ($open === []) {
            return [
                new ScannerFinding(
                    title: 'DNS zone transfer (AXFR) denied',
                    severity: FindingSeverity::Low,
                    source: 'dns',
                    category: 'passed',
                    description: 'AXFR attempts against authoritative NS were refused or empty.',
                    evidence: ['nameservers' => $nameservers],
                    fingerprint: 'dns-axfr-ok-'.$asset->id,
                ),
            ];
        }

        return [
            new ScannerFinding(
                title: 'DNS zone transfer (AXFR) allowed',
                severity: FindingSeverity::High,
                source: 'dns',
                category: 'axfr',
                description: 'One or more nameservers allow AXFR, exposing the full zone. Restrict transfers to authorized secondaries.',
                evidence: ['open_ns' => $open, 'nameservers' => $nameservers],
                fingerprint: 'dns-axfr-open-'.$asset->id,
            ),
        ];
    }

    /**
     * @return list<ScannerFinding>
     */
    private function analyzeWildcard(Asset $asset): array
    {
        $nonce = 'hackly-'.bin2hex(random_bytes(6));
        $host = $nonce.'.'.$asset->value;
        $ips = $this->digShort($host, 'A');
        $ips = array_values(array_filter(
            $ips,
            static fn (string $v): bool => filter_var($v, FILTER_VALIDATE_IP) !== false,
        ));

        if ($ips === []) {
            return [
                new ScannerFinding(
                    title: 'No wildcard DNS detected',
                    severity: FindingSeverity::Low,
                    source: 'dns',
                    category: 'passed',
                    description: "Random subdomain {$host} did not resolve.",
                    evidence: ['probe' => $host],
                    fingerprint: 'dns-wildcard-ok-'.$asset->id,
                ),
            ];
        }

        return [
            new ScannerFinding(
                title: 'Wildcard DNS detected',
                severity: FindingSeverity::Low,
                source: 'dns',
                category: 'wildcard',
                description: "Random host {$host} resolves (".implode(', ', $ips).'). Wildcard DNS expands takeover/scan surface.',
                evidence: ['probe' => $host, 'ips' => $ips],
                fingerprint: 'dns-wildcard-'.$asset->id,
            ),
        ];
    }

    /**
     * @return list<string>
     */
    private function digShort(string $name, string $type): array
    {
        $runner = app(BinaryRunner::class);
        $dig = $this->binary('dig');

        try {
            $result = $runner->run([$dig, '+short', $type, $name], 10);
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $line): string => rtrim(trim($line), '.'),
            explode("\n", $result->stdout),
        )));
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
