<?php

namespace App\Domain\Scanning\Scanners;

use App\Domain\Scanning\DTO\BinaryResult;
use App\Domain\Scanning\DTO\ScannerFinding;
use App\Domain\Scanning\Services\BinaryRunner;
use App\Enums\FindingSeverity;
use App\Enums\ScanTaskType;
use App\Models\Asset;
use App\Models\ScanTask;
use Illuminate\Support\Facades\Http;

class OriginExposureScanner extends AbstractScanner
{
    /** Common labels that often bypass CDN/proxy (grey-cloud). */
    private const ORIGIN_HINT_LABELS = [
        'direct', 'origin', 'origin-server', 'server', 'cpanel', 'ftp',
        'ssh', 'mail', 'webmail', 'remote', 'vpn', 'panel', 'hosting',
        'ns1', 'ns2', 'mx', 'smtp', 'pop', 'imap',
    ];

    public function type(): ScanTaskType
    {
        return ScanTaskType::OriginExposure;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('hackly.origin_exposure.timeout', 180);
    }

    public function estimateCost(): int
    {
        return 2;
    }

    public function buildCommand(Asset $asset, ScanTask $task, string $outputPath): array
    {
        $this->ensureOutputDir($outputPath);

        return [
            $this->binary('dig'),
            '+short',
            'A',
            $asset->value,
        ];
    }

    public function parse(Asset $asset, ScanTask $task, BinaryResult $result): array
    {
        $domain = strtolower(trim($asset->value));
        $edgeIps = $this->resolveIps($domain);
        $candidates = $this->collectCandidateIps($domain, $edgeIps);
        $findings = [];

        if ($candidates === []) {
            $findings[] = new ScannerFinding(
                title: 'No obvious origin IP candidates found',
                severity: FindingSeverity::Low,
                source: 'origin',
                category: 'passed',
                description: 'Probed common unproxied hostnames and MX/SPF infrastructure; no non-edge IPs stood out for direct Host-header tests.',
                evidence: [
                    'domain' => $domain,
                    'edge_ips' => $edgeIps,
                    'labels_checked' => self::ORIGIN_HINT_LABELS,
                ],
                fingerprint: 'origin-none-'.$asset->id,
            );

            return $findings;
        }

        $exposed = [];

        foreach ($candidates as $candidate) {
            $probe = $this->probeOrigin($domain, $candidate['ip'], $edgeIps);

            if ($probe['exposed']) {
                $exposed[] = array_merge($candidate, $probe);
            }

            usleep(150_000);
        }

        if ($exposed === []) {
            $findings[] = new ScannerFinding(
                title: 'Origin IP candidates not serving the site directly',
                severity: FindingSeverity::Low,
                source: 'origin',
                category: 'passed',
                description: 'Candidate IPs from MX/subdomains did not respond as the origin for this Host header (or only answered via CDN markers).',
                evidence: [
                    'domain' => $domain,
                    'edge_ips' => $edgeIps,
                    'candidates' => array_values($candidates),
                ],
                fingerprint: 'origin-ok-'.$asset->id,
            );

            return $findings;
        }

        foreach ($exposed as $row) {
            $findings[] = new ScannerFinding(
                title: "Origin IP reachable bypassing edge: {$row['ip']}",
                severity: FindingSeverity::High,
                source: 'origin',
                category: 'origin_exposure',
                description: "Direct request to {$row['ip']} with Host: {$domain} returned HTTP {$row['status']} without CDN markers. Cloudflare/WAF protection can be bypassed if this IP is the real origin.",
                evidence: [
                    'domain' => $domain,
                    'ip' => $row['ip'],
                    'source_host' => $row['host'] ?? null,
                    'source' => $row['source'] ?? null,
                    'status' => $row['status'],
                    'server' => $row['server'] ?? null,
                    'via' => $row['via'] ?? null,
                    'body_snippet' => $row['body_snippet'] ?? null,
                ],
                fingerprint: 'origin-exposed-'.$asset->id.'-'.$row['ip'],
            );
        }

        return $findings;
    }

    /**
     * @param  list<string>  $edgeIps
     * @return array<string, array{ip: string, host?: string, source: string}>
     */
    private function collectCandidateIps(string $domain, array $edgeIps): array
    {
        $candidates = [];
        $edgeLookup = array_fill_keys($edgeIps, true);

        foreach (self::ORIGIN_HINT_LABELS as $label) {
            $host = $label.'.'.$domain;
            foreach ($this->resolveIps($host) as $ip) {
                if (isset($edgeLookup[$ip]) || $this->isPrivateOrReservedIp($ip) || $this->looksLikeCloudflareAnycast($ip)) {
                    continue;
                }
                $candidates[$ip] = ['ip' => $ip, 'host' => $host, 'source' => 'subdomain'];
            }
        }

        foreach ($this->lookupMxHosts($domain) as $mxHost) {
            foreach ($this->resolveIps($mxHost) as $ip) {
                if (isset($edgeLookup[$ip]) || $this->isPrivateOrReservedIp($ip) || $this->looksLikeCloudflareAnycast($ip)) {
                    continue;
                }
                $candidates[$ip] = ['ip' => $ip, 'host' => $mxHost, 'source' => 'mx'];
            }
        }

        foreach ($this->spfMechanismIps($domain) as $ip) {
            if (isset($edgeLookup[$ip]) || $this->isPrivateOrReservedIp($ip) || $this->looksLikeCloudflareAnycast($ip)) {
                continue;
            }
            $candidates[$ip] = ['ip' => $ip, 'source' => 'spf'];
        }

        return $candidates;
    }

    /**
     * @param  list<string>  $edgeIps
     * @return array{exposed: bool, status?: int, server?: string, via?: string, body_snippet?: string}
     */
    private function probeOrigin(string $domain, string $ip, array $edgeIps): array
    {
        if (in_array($ip, $edgeIps, true)) {
            return ['exposed' => false];
        }

        foreach (['https', 'http'] as $scheme) {
            $port = $scheme === 'https' ? 443 : 80;
            // Hit the domain URL but force resolution to the candidate IP (correct Host + SNI).
            $url = "{$scheme}://{$domain}/";

            try {
                $response = Http::timeout(8)
                    ->withOptions([
                        'allow_redirects' => false,
                        'verify' => false,
                        'curl' => [
                            CURLOPT_RESOLVE => ["{$domain}:{$port}:{$ip}"],
                        ],
                    ])
                    ->withHeaders([
                        'User-Agent' => 'HacklySoftScanner/1.0',
                        'Accept' => 'text/html,application/xhtml+xml',
                    ])
                    ->get($url);
            } catch (\Throwable) {
                continue;
            }

            $status = $response->status();

            if (! in_array($status, [200, 201, 204, 301, 302, 307, 308, 401, 403], true)) {
                continue;
            }

            $headers = array_change_key_case($response->headers(), CASE_LOWER);
            $server = $this->firstHeader($headers, 'server');
            $cfRay = $this->firstHeader($headers, 'cf-ray');
            $via = $this->firstHeader($headers, 'via');

            if ($cfRay !== '' || str_contains(strtolower($server), 'cloudflare')) {
                continue;
            }

            $body = substr($response->body(), 0, 500);

            // Require some signal that this is an app/origin, not a random IP.
            $looksLikeSite = $body !== ''
                || str_contains(strtolower($server), 'nginx')
                || str_contains(strtolower($server), 'apache')
                || str_contains(strtolower($server), 'litespeed')
                || in_array($status, [200, 301, 302, 401, 403], true);

            if (! $looksLikeSite) {
                continue;
            }

            return [
                'exposed' => true,
                'status' => $status,
                'server' => $server !== '' ? $server : null,
                'via' => $via !== '' ? $via : null,
                'body_snippet' => $body !== '' ? $body : null,
            ];
        }

        return ['exposed' => false];
    }

    /**
     * @return list<string>
     */
    private function resolveIps(string $host): array
    {
        $runner = app(BinaryRunner::class);
        $dig = $this->binary('dig');
        $ips = [];

        foreach (['A', 'AAAA'] as $type) {
            try {
                $result = $runner->run([$dig, '+short', $type, $host], 8);
            } catch (\Throwable) {
                continue;
            }

            foreach (preg_split('/\r\n|\r|\n/', $result->stdout) ?: [] as $line) {
                $line = rtrim(trim($line), '.');
                if (filter_var($line, FILTER_VALIDATE_IP) !== false) {
                    $ips[] = $line;
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * @return list<string>
     */
    private function lookupMxHosts(string $domain): array
    {
        $runner = app(BinaryRunner::class);
        $dig = $this->binary('dig');
        $hosts = [];

        try {
            $result = $runner->run([$dig, '+short', 'MX', $domain], 10);
        } catch (\Throwable) {
            return [];
        }

        foreach (preg_split('/\r\n|\r|\n/', $result->stdout) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^\d+\s+(\S+)\.?$/', $line, $m)) {
                $hosts[] = strtolower(rtrim($m[1], '.'));
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @return list<string>
     */
    private function spfMechanismIps(string $domain): array
    {
        $runner = app(BinaryRunner::class);
        $dig = $this->binary('dig');
        $ips = [];

        try {
            $result = $runner->run([$dig, '+short', 'TXT', $domain], 10);
        } catch (\Throwable) {
            return [];
        }

        foreach (preg_split('/\r\n|\r|\n/', $result->stdout) ?: [] as $line) {
            $txt = $this->joinTxt($line);
            if (! str_starts_with(strtolower($txt), 'v=spf1')) {
                continue;
            }

            if (preg_match_all('/\bip4:([0-9.]+)(?:\/\d+)?/i', $txt, $m)) {
                foreach ($m[1] as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $ips[] = $ip;
                    }
                }
            }

            if (preg_match_all('/\bip6:([0-9a-f:]+)(?:\/\d+)?/i', $txt, $m6)) {
                foreach ($m6[1] as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $ips[] = $ip;
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function joinTxt(string $line): string
    {
        if (preg_match_all('/"([^"]*)"/', $line, $parts)) {
            return implode('', $parts[1]);
        }

        return trim($line, '"');
    }

    /**
     * @param  array<string, list<string>|string>  $headers
     */
    private function firstHeader(array $headers, string $name): string
    {
        $value = $headers[$name] ?? '';

        if (is_array($value)) {
            return (string) ($value[0] ?? '');
        }

        return (string) $value;
    }

    private function isPrivateOrReservedIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * Rough Cloudflare anycast heuristic (common published ranges).
     * Not exhaustive — reduces false positives on edge IPs.
     */
    private function looksLikeCloudflareAnycast(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $ranges = [
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '162.158.0.0/15',
            '188.114.96.0/20',
            '190.93.240.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
        ];

        foreach ($ranges as $cidr) {
            if ($this->ipv4InCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function ipv4InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $mask = (int) $mask;

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $maskLong = -1 << (32 - $mask);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
