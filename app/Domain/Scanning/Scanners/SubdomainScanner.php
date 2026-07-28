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
use Illuminate\Support\Facades\Http;

class SubdomainScanner extends AbstractScanner
{
    public function type(): ScanTaskType
    {
        return ScanTaskType::SubdomainEnum;
    }

    public function supports(Asset $asset): bool
    {
        return $asset->type === AssetType::Domain;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('hackly.subdomain.timeout', 180);
    }

    public function estimateCost(): int
    {
        return 2;
    }

    public function buildCommand(Asset $asset, ScanTask $task, string $outputPath): array
    {
        $this->ensureOutputDir($outputPath);

        // Soft enumeration: resolve a small wordlist via dig in a generated script file handled by runner as dig NS.
        return [
            $this->binary('dig'),
            '+short',
            'NS',
            $asset->value,
        ];
    }

    public function parse(Asset $asset, ScanTask $task, BinaryResult $result): array
    {
        $discovered = [];
        $ns = array_values(array_filter(array_map('trim', explode("\n", $result->stdout))));

        $wordlistPath = (string) config('hackly.subdomain.wordlist');
        $words = is_file($wordlistPath)
            ? array_values(array_filter(array_map('trim', file($wordlistPath, FILE_IGNORE_NEW_LINES))))
            : ['www', 'mail', 'api', 'dev', 'staging', 'admin', 'cdn', 'vpn', 'test'];

        $runner = app(BinaryRunner::class);
        $dig = $this->binary('dig');

        foreach (array_slice($words, 0, 50) as $word) {
            $host = $word.'.'.$asset->value;
            $records = $this->resolveHostRecords($runner, $dig, $host);

            if ($records['ips'] !== [] || $records['cnames'] !== []) {
                $discovered[$host] = $records;
            }

            usleep(100_000);
        }

        if (config('hackly.subdomain.ct_logs_enabled')) {
            foreach ($this->fetchCertificateTransparency($asset->value) as $host) {
                $discovered[$host] = $discovered[$host] ?? $this->resolveHostRecords($runner, $dig, $host);
            }
        }

        $findings = [];

        if ($ns !== []) {
            $findings[] = new ScannerFinding(
                title: 'Authoritative nameservers',
                severity: FindingSeverity::Low,
                source: 'dns',
                category: 'nameservers',
                description: 'NS: '.implode(', ', $ns),
                evidence: ['ns' => $ns],
                fingerprint: 'ns-'.$asset->id,
            );
        }

        foreach ($discovered as $host => $records) {
            $ips = $records['ips'] ?? [];
            $cnames = $records['cnames'] ?? [];
            $details = [];

            if ($ips !== []) {
                $details[] = 'IP: '.implode(', ', $ips);
            }

            if ($cnames !== []) {
                $details[] = 'CNAME: '.implode(', ', $cnames);
            }

            if ($details === []) {
                $details[] = 'Host discovered (no A/AAAA/CNAME resolved).';
            }

            $findings[] = new ScannerFinding(
                title: "Subdomain discovered: {$host}",
                severity: FindingSeverity::Low,
                source: 'dns',
                category: 'subdomain',
                description: implode(' · ', $details),
                evidence: [
                    'host' => $host,
                    'ips' => $ips,
                    'cnames' => $cnames,
                    'a' => $records['a'] ?? [],
                    'aaaa' => $records['aaaa'] ?? [],
                ],
                fingerprint: 'subdomain-'.$asset->id.'-'.$host,
            );
        }

        return $findings;
    }

    /**
     * @return array{ips: list<string>, a: list<string>, aaaa: list<string>, cnames: list<string>}
     */
    private function resolveHostRecords(BinaryRunner $runner, string $dig, string $host): array
    {
        $rawA = $this->digShort($runner, $dig, $host, 'A');
        $rawAaaa = $this->digShort($runner, $dig, $host, 'AAAA');
        $cnames = $this->digShort($runner, $dig, $host, 'CNAME');

        $a = array_values(array_filter(
            $rawA,
            static fn (string $value): bool => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
        ));
        $aaaa = array_values(array_filter(
            $rawAaaa,
            static fn (string $value): bool => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
        ));

        // dig +short A often prepends CNAME targets before the final A record.
        foreach ([...$rawA, ...$rawAaaa] as $value) {
            if (
                filter_var($value, FILTER_VALIDATE_IP) === false
                && filled($value)
                && ! in_array($value, $cnames, true)
            ) {
                $cnames[] = $value;
            }
        }

        return [
            'a' => $a,
            'aaaa' => $aaaa,
            'cnames' => array_values(array_unique($cnames)),
            'ips' => array_values(array_unique([...$a, ...$aaaa])),
        ];
    }

    /**
     * @return list<string>
     */
    private function digShort(BinaryRunner $runner, string $dig, string $host, string $type): array
    {
        try {
            $lookup = $runner->run([$dig, '+short', $host, $type], 10);

            return array_values(array_filter(array_map(
                static fn (string $line): string => rtrim(trim($line), '.'),
                explode("\n", $lookup->stdout),
            )));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function fetchCertificateTransparency(string $domain): array
    {
        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->get('https://crt.sh/', [
                    'q' => '%.'.$domain,
                    'output' => 'json',
                ]);

            if (! $response->successful()) {
                return [];
            }

            $hosts = [];

            foreach ($response->json() ?? [] as $row) {
                $name = strtolower((string) ($row['name_value'] ?? ''));

                foreach (preg_split('/\s+/', $name) ?: [] as $candidate) {
                    $candidate = trim($candidate);
                    if ($candidate === '' || str_contains($candidate, '*')) {
                        continue;
                    }
                    if (str_ends_with($candidate, '.'.$domain) || $candidate === $domain) {
                        $hosts[$candidate] = true;
                    }
                }
            }

            return array_slice(array_keys($hosts), 0, 100);
        } catch (\Throwable) {
            return [];
        }
    }
}
