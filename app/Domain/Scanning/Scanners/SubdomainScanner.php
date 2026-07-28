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

            try {
                $lookup = $runner->run([$dig, '+short', $host, 'A'], 10);
                $answers = array_values(array_filter(array_map('trim', explode("\n", $lookup->stdout))));

                if ($answers !== []) {
                    $discovered[$host] = $answers;
                }
            } catch (\Throwable) {
                continue;
            }

            usleep(100_000);
        }

        if (config('hackly.subdomain.ct_logs_enabled')) {
            foreach ($this->fetchCertificateTransparency($asset->value) as $host) {
                $discovered[$host] = $discovered[$host] ?? [];
            }
        }

        $findings = [];

        if ($ns !== []) {
            $findings[] = new ScannerFinding(
                title: 'Authoritative nameservers',
                severity: FindingSeverity::Low,
                source: 'dns',
                category: 'nameservers',
                evidence: ['ns' => $ns],
                fingerprint: 'ns-'.$asset->id,
            );
        }

        foreach ($discovered as $host => $ips) {
            $findings[] = new ScannerFinding(
                title: "Subdomain discovered: {$host}",
                severity: FindingSeverity::Low,
                source: 'dns',
                category: 'subdomain',
                evidence: ['host' => $host, 'ips' => $ips],
                fingerprint: 'subdomain-'.$asset->id.'-'.$host,
            );
        }

        return $findings;
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
