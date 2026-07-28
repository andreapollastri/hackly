<?php

namespace App\Domain\Scanning\Scanners;

use App\Domain\Scanning\DTO\BinaryResult;
use App\Domain\Scanning\DTO\ScannerFinding;
use App\Enums\FindingSeverity;
use App\Enums\ScanTaskType;
use App\Models\Asset;
use App\Models\ScanTask;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TechFingerprintScanner extends AbstractScanner
{
    public function type(): ScanTaskType
    {
        return ScanTaskType::TechFingerprint;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('hackly.tech_fingerprint.timeout', 60);
    }

    public function estimateCost(): int
    {
        return 1;
    }

    public function buildCommand(Asset $asset, ScanTask $task, string $outputPath): array
    {
        $this->ensureOutputDir($outputPath);

        // Soft HTTP fingerprinting runs in parse(); dig is a cheap placeholder command.
        return [
            $this->binary('dig'),
            '+short',
            $asset->isDomain() ? $asset->value : 'localhost',
        ];
    }

    public function parse(Asset $asset, ScanTask $task, BinaryResult $result): array
    {
        $base = rtrim($asset->httpBaseUrl(), '/');
        $findings = [];

        try {
            $home = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'HacklySoftScanner/1.0'])
                ->withOptions(['allow_redirects' => false])
                ->get($base.'/');
        } catch (\Throwable) {
            return [];
        }

        $findings = array_merge(
            $findings,
            $this->fromPoweredBy($home, $asset, $base),
            $this->fromCookies($home, $asset, $base),
            $this->fromBodySignals($home, $asset, $base, 'home'),
        );

        $probePath = '/'.Str::lower(Str::random(12)).'-hackly-probe';

        try {
            $probe = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'HacklySoftScanner/1.0'])
                ->withOptions(['allow_redirects' => false])
                ->get($base.$probePath);

            $findings = array_merge(
                $findings,
                $this->fromBodySignals($probe, $asset, $base.$probePath, 'debug_probe'),
            );
        } catch (\Throwable) {
            // ignore network errors
        }

        return $findings;
    }

    /**
     * @return list<ScannerFinding>
     */
    private function fromPoweredBy(Response $response, Asset $asset, string $url): array
    {
        $poweredBy = (string) $response->header('X-Powered-By');

        if ($poweredBy === '' || ! str_contains(strtolower($poweredBy), 'php')) {
            return [];
        }

        return [
            new ScannerFinding(
                title: "PHP version disclosed ({$poweredBy})",
                severity: FindingSeverity::Low,
                source: 'http',
                category: 'tech_fingerprint',
                description: 'The X-Powered-By response header reveals the PHP runtime.',
                evidence: [
                    'url' => $url,
                    'header' => 'X-Powered-By',
                    'value' => $poweredBy,
                ],
                fingerprint: 'tech-php-powered-by-'.$asset->id,
            ),
        ];
    }

    /**
     * @return list<ScannerFinding>
     */
    private function fromCookies(Response $response, Asset $asset, string $url): array
    {
        $cookies = $this->cookieNames($response);
        $findings = [];

        if (in_array('laravel_session', $cookies, true) || in_array('XSRF-TOKEN', $cookies, true)) {
            $matched = array_values(array_intersect(['laravel_session', 'XSRF-TOKEN'], $cookies));

            $findings[] = new ScannerFinding(
                title: 'Laravel session cookies detected',
                severity: FindingSeverity::Low,
                source: 'http',
                category: 'tech_fingerprint',
                description: 'Response Set-Cookie headers match Laravel defaults (laravel_session / XSRF-TOKEN).',
                evidence: [
                    'url' => $url,
                    'cookies' => $matched,
                ],
                fingerprint: 'tech-laravel-cookies-'.$asset->id,
            );
        }

        return $findings;
    }

    /**
     * @return list<ScannerFinding>
     */
    private function fromBodySignals(Response $response, Asset $asset, string $url, string $context): array
    {
        $body = Str::limit($response->body(), 200_000, '');
        $lower = strtolower($body);
        $findings = [];

        if (
            str_contains($lower, 'ignition')
            || str_contains($lower, 'spatie\\ignition')
            || str_contains($lower, 'flareapp')
            || (str_contains($lower, 'whoops') && str_contains($lower, 'stack trace'))
            || str_contains($lower, 'app_debug')
        ) {
            $findings[] = new ScannerFinding(
                title: 'Possible APP_DEBUG / Ignition error page exposed',
                severity: FindingSeverity::Medium,
                source: 'http',
                category: 'tech_fingerprint',
                description: 'Error page content suggests Laravel debug mode (Ignition/Whoops) is enabled.',
                evidence: [
                    'url' => $url,
                    'context' => $context,
                    'status' => $response->status(),
                    'signals' => array_values(array_filter([
                        str_contains($lower, 'ignition') ? 'ignition' : null,
                        str_contains($lower, 'whoops') ? 'whoops' : null,
                        str_contains($lower, 'flareapp') ? 'flare' : null,
                    ])),
                ],
                fingerprint: 'tech-laravel-debug-'.$asset->id,
            );
        }

        if (preg_match('/laravel[\/\s-]?v?(\d+(?:\.\d+){0,2})/i', $body, $m)) {
            $findings[] = new ScannerFinding(
                title: 'Laravel version hint detected ('.$m[1].')',
                severity: FindingSeverity::Low,
                source: 'http',
                category: 'tech_fingerprint',
                description: 'Page content includes a Laravel version string.',
                evidence: [
                    'url' => $url,
                    'version' => $m[1],
                    'context' => $context,
                ],
                fingerprint: 'tech-laravel-version-'.$asset->id,
            );
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function cookieNames(Response $response): array
    {
        $header = $response->header('Set-Cookie');

        if (is_array($header)) {
            $raw = $header;
        } elseif (is_string($header) && $header !== '') {
            $raw = [$header];
        } else {
            // Guzzle may expose multiple Set-Cookie via getHeaders().
            $raw = $response->headers()['Set-Cookie'] ?? $response->headers()['set-cookie'] ?? [];
            if (is_string($raw)) {
                $raw = [$raw];
            }
        }

        $names = [];

        foreach ($raw as $cookie) {
            if (! is_string($cookie) || $cookie === '') {
                continue;
            }

            $name = strtok($cookie, '=');
            if (is_string($name) && $name !== '') {
                $names[] = trim($name);
            }
        }

        return array_values(array_unique($names));
    }
}
