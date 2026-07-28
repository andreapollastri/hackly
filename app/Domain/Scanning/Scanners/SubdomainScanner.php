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

class SubdomainScanner extends AbstractScanner
{
    /**
     * Fingerprints from can-i-take-over-xyz (dangling CNAME → unclaimed service).
     *
     * @var list<array{service: string, cname: string, body: string}>
     */
    private const TAKEOVER_FINGERPRINTS = [
        ['service' => 'GitHub Pages', 'cname' => 'github.io', 'body' => "There isn't a GitHub Pages site here"],
        ['service' => 'Heroku', 'cname' => 'herokuapp.com', 'body' => 'no such app'],
        ['service' => 'AWS S3', 'cname' => 'amazonaws.com', 'body' => 'NoSuchBucket'],
        ['service' => 'AWS S3', 'cname' => 's3.amazonaws.com', 'body' => 'The specified bucket does not exist'],
        ['service' => 'Azure', 'cname' => 'azurewebsites.net', 'body' => '404 Web Site not found'],
        ['service' => 'Azure', 'cname' => 'cloudapp.azure.com', 'body' => '404 Web Site not found'],
        ['service' => 'Cloudfront', 'cname' => 'cloudfront.net', 'body' => 'Bad request'],
        ['service' => 'Zendesk', 'cname' => 'zendesk.com', 'body' => 'Help Center Closed'],
        ['service' => 'Shopify', 'cname' => 'myshopify.com', 'body' => 'Sorry, this shop is currently unavailable'],
        ['service' => 'Surge', 'cname' => 'surge.sh', 'body' => 'project not found'],
        ['service' => 'Pantheon', 'cname' => 'pantheonsite.io', 'body' => '404 error unknown site'],
        ['service' => 'Netlify', 'cname' => 'netlify.app', 'body' => 'Not Found - Request ID'],
        ['service' => 'Netlify', 'cname' => 'netlify.com', 'body' => 'Not Found - Request ID'],
        ['service' => 'Ghost', 'cname' => 'ghost.io', 'body' => 'The thing you were looking for is no longer here'],
        ['service' => 'Readme', 'cname' => 'readme.io', 'body' => 'Project doesnt exist'],
        ['service' => 'Tumblr', 'cname' => 'tumblr.com', 'body' => "There's nothing here"],
        ['service' => 'WordPress.com', 'cname' => 'wordpress.com', 'body' => 'Do you want to register'],
        ['service' => 'Cargo', 'cname' => 'cargocollective.com', 'body' => '404 Not Found'],
        ['service' => 'Feedpress', 'cname' => 'feedpress.me', 'body' => 'The feed has not been found'],
        ['service' => 'Help Juice', 'cname' => 'helpjuice.com', 'body' => "We could not find what you're looking for"],
        ['service' => 'Help Scout', 'cname' => 'helpscoutdocs.com', 'body' => 'No settings were found for this company'],
        ['service' => 'JetBrains', 'cname' => 'youtrack.cloud', 'body' => 'is not a registered InCloud YouTrack'],
        ['service' => 'Ngrok', 'cname' => 'ngrok.io', 'body' => 'ngrok.io not found'],
        ['service' => 'SmugMug', 'cname' => 'smugmug.com', 'body' => 'Page Not Found'],
        ['service' => 'Statuspage', 'cname' => 'statuspage.io', 'body' => 'Status page targeted'],
        ['service' => 'Strikingly', 'cname' => 'strikinglydns.com', 'body' => 'page not found'],
        ['service' => 'Uberflip', 'cname' => 'uberflip.com', 'body' => 'Non-hub domain'],
        ['service' => 'Unbounce', 'cname' => 'unbouncepages.com', 'body' => 'The requested URL was not found'],
        ['service' => 'UserVoice', 'cname' => 'uservoice.com', 'body' => 'This UserVoice subdomain is currently available'],
        ['service' => 'Webflow', 'cname' => 'webflow.io', 'body' => 'The page you are looking for doesn\'t exist'],
        ['service' => 'Worksites', 'cname' => 'worksites.net', 'body' => 'Hello! Sorry, but the website'],
    ];

    public function type(): ScanTaskType
    {
        return ScanTaskType::SubdomainEnum;
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

            $takeover = $this->detectTakeover($asset, $host, $cnames);
            if ($takeover !== null) {
                $findings[] = $takeover;
            }
        }

        return $findings;
    }

    /**
     * @param  list<string>  $cnames
     */
    private function detectTakeover(Asset $asset, string $host, array $cnames): ?ScannerFinding
    {
        if ($cnames === []) {
            return null;
        }

        $cnameHaystack = strtolower(implode(' ', $cnames));
        $matched = null;

        foreach (self::TAKEOVER_FINGERPRINTS as $fp) {
            if (str_contains($cnameHaystack, strtolower($fp['cname']))) {
                $matched = $fp;
                break;
            }
        }

        if ($matched === null) {
            return null;
        }

        $body = '';

        try {
            $response = Http::timeout(10)
                ->withOptions(['allow_redirects' => true, 'verify' => false])
                ->withHeaders(['User-Agent' => 'HacklySoftScanner/1.0'])
                ->get('https://'.$host);
            $body = $response->body();
        } catch (\Throwable) {
            try {
                $response = Http::timeout(8)
                    ->withOptions(['allow_redirects' => true, 'verify' => false])
                    ->withHeaders(['User-Agent' => 'HacklySoftScanner/1.0'])
                    ->get('http://'.$host);
                $body = $response->body();
            } catch (\Throwable) {
                return null;
            }
        }

        if (! str_contains($body, $matched['body'])) {
            return null;
        }

        return new ScannerFinding(
            title: "Possible subdomain takeover: {$host} ({$matched['service']})",
            severity: FindingSeverity::High,
            source: 'dns',
            category: 'subdomain_takeover',
            description: "{$host} CNAMEs to {$matched['service']} and the HTTP body matches an unclaimed-service fingerprint. Claim the resource or remove the dangling DNS record.",
            evidence: [
                'host' => $host,
                'cnames' => $cnames,
                'service' => $matched['service'],
                'fingerprint' => $matched['body'],
            ],
            fingerprint: 'subdomain-takeover-'.$asset->id.'-'.$host,
        );
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
