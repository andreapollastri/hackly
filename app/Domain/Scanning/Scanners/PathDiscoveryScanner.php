<?php

namespace App\Domain\Scanning\Scanners;

use App\Domain\Scanning\DTO\BinaryResult;
use App\Domain\Scanning\DTO\ScannerFinding;
use App\Domain\Scanning\Services\BinaryRunner;
use App\Domain\Scanning\Support\AppSurfaceCatalog;
use App\Domain\Scanning\Support\NucleiRuntime;
use App\Enums\FindingSeverity;
use App\Enums\ScanTaskType;
use App\Models\Asset;
use App\Models\ScanTask;
use Illuminate\Support\Facades\Http;

class PathDiscoveryScanner extends AbstractScanner
{
    public function type(): ScanTaskType
    {
        return ScanTaskType::PathDiscovery;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('hackly.path_discovery.timeout', 300);
    }

    public function estimateCost(): int
    {
        return 2;
    }

    public function processEnvironment(): array
    {
        $nuclei = $this->binary('nuclei');

        if (! app(BinaryRunner::class)->binaryExists($nuclei)) {
            return [];
        }

        return NucleiRuntime::environment();
    }

    public function workingDirectory(): ?string
    {
        $nuclei = $this->binary('nuclei');

        if (! app(BinaryRunner::class)->binaryExists($nuclei)) {
            return null;
        }

        return NucleiRuntime::home();
    }

    public function buildCommand(Asset $asset, ScanTask $task, string $outputPath): array
    {
        $this->ensureOutputDir($outputPath);

        // Prefer nuclei http discovery if available; otherwise a no-op dig used as placeholder —
        // actual soft path probing happens in parse() with rate-limited HTTP HEAD.
        $nuclei = $this->binary('nuclei');
        $runner = app(BinaryRunner::class);

        if ($runner->binaryExists($nuclei)) {
            $jsonl = $outputPath.'.jsonl';

            return array_merge(
                [$nuclei],
                NucleiRuntime::baseFlags(),
                [
                    '-u',
                    $asset->httpBaseUrl(),
                    '-tags',
                    (string) config('hackly.path_discovery.tags', 'discovery,exposure,config,laravel,php,dotenv'),
                    '-rate-limit',
                    (string) config('hackly.path_discovery.rate_limit', 20),
                    '-c',
                    '3',
                    '-silent',
                    '-jsonl',
                    '-o',
                    $jsonl,
                ],
            );
        }

        return [
            $this->binary('dig'),
            '+short',
            $asset->isDomain() ? $asset->value : 'localhost',
        ];
    }

    public function parse(Asset $asset, ScanTask $task, BinaryResult $result): array
    {
        $findings = [];
        $jsonl = ($result->outputPath ?? '').'.jsonl';

        if (is_file($jsonl)) {
            foreach (file($jsonl, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $row = json_decode($line, true);
                if (! is_array($row)) {
                    continue;
                }

                $finding = $this->findingFromNucleiRow($row, $asset);
                if ($finding !== null) {
                    $findings[] = $finding;
                }
            }
        }

        // Soft HTTP path probing always runs (covers PHP/Laravel paths even when Nuclei is used).
        return array_merge($findings, $this->probeWordlist($asset), $this->probeLinkedRoutes($asset));
    }

    /**
     * Wordlist paths, with routes learned from linked repositories placed first.
     *
     * @return list<string>
     */
    public function discoveryPaths(Asset $asset): array
    {
        $wordlistPath = (string) config('hackly.path_discovery.wordlist');
        $paths = is_file($wordlistPath)
            ? array_values(array_filter(array_map('trim', file($wordlistPath, FILE_IGNORE_NEW_LINES))))
            : ['/', '/robots.txt', '/sitemap.xml', '/.well-known/security.txt', '/health', '/api', '/login', '/admin', '/.env', '/up'];

        $maxPaths = max(1, (int) config('hackly.path_discovery.max_paths', 80));

        return array_slice(array_values(array_unique([...AppSurfaceCatalog::pathsFor($asset), ...$paths])), 0, $maxPaths);
    }

    /**
     * @return list<ScannerFinding>
     */
    private function probeWordlist(Asset $asset): array
    {
        $paths = $this->discoveryPaths($asset);
        $linked = array_flip(AppSurfaceCatalog::pathsFor($asset));
        $base = rtrim($asset->httpBaseUrl(), '/');
        $rateLimit = max(1, (int) config('hackly.path_discovery.rate_limit', 20));
        $delayMicros = (int) (1_000_000 / $rateLimit);
        $findings = [];

        foreach ($paths as $path) {
            if (isset($linked[$path])) {
                continue;
            }

            $url = $base.(str_starts_with($path, '/') ? $path : '/'.$path);

            try {
                $response = Http::timeout(8)
                    ->withHeaders(['User-Agent' => 'HacklySoftScanner/1.0'])
                    ->withOptions(['allow_redirects' => false])
                    ->head($url);

                $status = $response->status();

                if (in_array($status, [200, 201, 204, 301, 302, 307, 401, 403], true)) {
                    $findings[] = new ScannerFinding(
                        title: "Path discovered: {$path} (HTTP {$status})",
                        severity: $this->pathSeverity($path, $status),
                        source: 'http',
                        category: 'path_discovery',
                        evidence: [
                            'url' => $url,
                            'status' => $status,
                        ],
                        fingerprint: 'path-'.$asset->id.'-'.$path,
                    );
                }
            } catch (\Throwable) {
                // ignore network errors for soft discovery
            }

            usleep($delayMicros);
        }

        return $findings;
    }

    /**
     * Routes remembered from linked repositories. Ordinary pages are not findings.
     *
     * @return list<ScannerFinding>
     */
    private function probeLinkedRoutes(Asset $asset): array
    {
        $paths = AppSurfaceCatalog::pathsFor($asset);

        if ($paths === []) {
            return [];
        }

        $base = rtrim($asset->httpBaseUrl(), '/');
        $rateLimit = max(1, (int) config('hackly.path_discovery.rate_limit', 20));
        $delayMicros = (int) (1_000_000 / $rateLimit);
        $findings = [];

        foreach ($paths as $path) {
            $url = $base.$path;

            try {
                $response = Http::timeout(8)
                    ->withHeaders(['User-Agent' => 'HacklySoftScanner/1.0'])
                    ->withOptions(['allow_redirects' => false])
                    ->head($url);
                $status = $response->status();
            } catch (\Throwable) {
                usleep($delayMicros);

                continue;
            }

            if ($this->isNotableLinkedPath($path, $status)) {
                $findings[] = new ScannerFinding(
                    title: "Linked application route: {$path} (HTTP {$status})",
                    severity: $this->pathSeverity($path, $status),
                    source: 'http',
                    category: 'path_discovery',
                    description: 'This path comes from a repository linked to '.$asset->value.'.',
                    evidence: [
                        'url' => $url,
                        'status' => $status,
                        'origin' => 'linked_repository',
                    ],
                    fingerprint: 'path-linked-'.$asset->id.'-'.$path,
                );
            }

            usleep($delayMicros);
        }

        $findings[] = new ScannerFinding(
            title: 'Linked application routes checked',
            severity: FindingSeverity::Low,
            source: 'http',
            category: 'passed',
            description: 'Checked '.count($paths).' routes learned from repositories linked to '.$asset->value.'.',
            evidence: ['paths' => count($paths), 'origin' => 'linked_repository'],
            fingerprint: 'path-linked-summary-'.$asset->id,
        );

        return $findings;
    }

    private function isNotableLinkedPath(string $path, int $status): bool
    {
        if (! in_array($status, [200, 201, 204, 301, 302, 307, 401, 403], true)) {
            return false;
        }

        $lower = strtolower($path);

        foreach (['.env', '.git', 'laravel.log', 'phpinfo', 'telescope', 'horizon', 'actuator', 'wp-config', 'debug', 'elmah', 'eval-stdin'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function pathSeverity(string $path, int $status): FindingSeverity
    {
        if (! in_array($status, [200, 201, 204], true)) {
            // 401/403 on sensitive paths still worth noting but lower.
            $sensitiveDenied = [
                '/.env', '/.git/HEAD', '/.git/config', '/telescope', '/horizon',
                '/_ignition/execute-solution', '/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php',
            ];

            return in_array($path, $sensitiveDenied, true)
                ? FindingSeverity::Low
                : FindingSeverity::Low;
        }

        $critical = [
            '/.env',
            '/.env.backup',
            '/.env.old',
            '/.env.local',
            '/.env.production',
            '/.env.bak',
            '/.env.save',
            '/.git/HEAD',
            '/.git/config',
            '/storage/logs/laravel.log',
            '/backup.sql',
            '/_ignition/execute-solution',
            '/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php',
            '/phpinfo.php',
        ];

        $high = [
            '/telescope',
            '/horizon',
            '/pulse',
            '/nova',
            '/log-viewer',
            '/_debugbar',
            '/_debugbar/open',
            '/_ignition',
            '/composer.lock',
            '/composer.json',
            '/vendor/composer/installed.json',
        ];

        if (in_array($path, $critical, true)) {
            return FindingSeverity::High;
        }

        if (in_array($path, $high, true)) {
            return FindingSeverity::High;
        }

        return FindingSeverity::Low;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function findingFromNucleiRow(array $row, Asset $asset): ?ScannerFinding
    {
        $templateId = (string) ($row['template-id'] ?? $row['templateID'] ?? 'unknown');
        $info = $row['info'] ?? [];
        $name = (string) ($info['name'] ?? $templateId);
        $severity = $this->mapSeverity((string) ($info['severity'] ?? 'info'));

        return new ScannerFinding(
            title: $name,
            severity: $severity,
            source: 'nuclei',
            category: 'path_discovery',
            description: (string) ($info['description'] ?? ''),
            evidence: [
                'template' => $templateId,
                'matched_at' => $row['matched-at'] ?? $row['host'] ?? null,
            ],
            fingerprint: 'nuclei-path-'.$asset->id.'-'.$templateId.'-'.md5((string) ($row['matched-at'] ?? $name)),
        );
    }

    private function mapSeverity(string $severity): FindingSeverity
    {
        return FindingSeverity::normalize($severity);
    }
}
