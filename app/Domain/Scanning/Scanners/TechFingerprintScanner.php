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
    /** @var list<string> */
    private const SECURITY_HEADERS = [
        'strict-transport-security',
        'content-security-policy',
        'x-content-type-options',
        'x-frame-options',
        'referrer-policy',
        'permissions-policy',
    ];

    public function type(): ScanTaskType
    {
        return ScanTaskType::TechFingerprint;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('hackly.tech_fingerprint.timeout', 90);
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
            $this->fromSecurityHeaders($home, $asset, $base),
            $this->fromCors($asset, $base),
            $this->fromHttpMethods($asset, $base),
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
        $cookies = $this->parseCookies($response);
        $findings = [];
        $names = array_column($cookies, 'name');

        if (in_array('laravel_session', $names, true) || in_array('XSRF-TOKEN', $names, true)) {
            $matched = array_values(array_intersect(['laravel_session', 'XSRF-TOKEN'], $names));

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

        foreach ($cookies as $cookie) {
            $name = $cookie['name'];
            $attrs = $cookie['attrs'];

            // XSRF-TOKEN must be JS-readable in Laravel — not a finding.
            if (strcasecmp($name, 'XSRF-TOKEN') === 0) {
                continue;
            }

            $isSessionLike = str_contains(strtolower($name), 'session')
                || strcasecmp($name, 'laravel_session') === 0
                || str_starts_with($name, '__Host-')
                || str_starts_with($name, '__Secure-');

            if (! $cookie['httponly'] && $isSessionLike) {
                $findings[] = new ScannerFinding(
                    title: "Cookie missing HttpOnly: {$name}",
                    severity: FindingSeverity::Medium,
                    source: 'http',
                    category: 'cookie',
                    description: 'Session-like cookie is readable by JavaScript (XSS → session theft).',
                    evidence: ['url' => $url, 'cookie' => $name, 'attrs' => $attrs],
                    fingerprint: 'cookie-no-httponly-'.$asset->id.'-'.$name,
                );
            }

            if (! $cookie['secure']) {
                $findings[] = new ScannerFinding(
                    title: "Cookie missing Secure: {$name}",
                    severity: FindingSeverity::Medium,
                    source: 'http',
                    category: 'cookie',
                    description: 'Cookie can be sent over HTTP. Set the Secure flag for HTTPS-only sites.',
                    evidence: ['url' => $url, 'cookie' => $name, 'attrs' => $attrs],
                    fingerprint: 'cookie-no-secure-'.$asset->id.'-'.$name,
                );
            }

            $sameSite = strtolower((string) ($cookie['samesite'] ?? ''));

            if ($sameSite === '') {
                $findings[] = new ScannerFinding(
                    title: "Cookie missing SameSite: {$name}",
                    severity: FindingSeverity::Low,
                    source: 'http',
                    category: 'cookie',
                    description: 'SameSite is absent. Prefer SameSite=Lax or Strict to mitigate CSRF.',
                    evidence: ['url' => $url, 'cookie' => $name, 'attrs' => $attrs],
                    fingerprint: 'cookie-no-samesite-'.$asset->id.'-'.$name,
                );
            } elseif ($sameSite === 'none' && ! $cookie['secure']) {
                $findings[] = new ScannerFinding(
                    title: "Cookie SameSite=None without Secure: {$name}",
                    severity: FindingSeverity::Medium,
                    source: 'http',
                    category: 'cookie',
                    description: 'SameSite=None requires the Secure attribute; browsers will reject this cookie.',
                    evidence: ['url' => $url, 'cookie' => $name, 'attrs' => $attrs],
                    fingerprint: 'cookie-samesite-none-'.$asset->id.'-'.$name,
                );
            }

            $domainAttr = (string) ($cookie['domain'] ?? '');
            if ($domainAttr !== '' && str_starts_with($domainAttr, '.')) {
                $findings[] = new ScannerFinding(
                    title: "Cookie scoped to parent domain: {$name}",
                    severity: FindingSeverity::Low,
                    source: 'http',
                    category: 'cookie',
                    description: "Cookie Domain={$domainAttr} is shared with subdomains. Prefer host-only cookies when possible.",
                    evidence: ['url' => $url, 'cookie' => $name, 'domain' => $domainAttr],
                    fingerprint: 'cookie-broad-domain-'.$asset->id.'-'.$name,
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<ScannerFinding>
     */
    private function fromSecurityHeaders(Response $response, Asset $asset, string $url): array
    {
        $findings = [];
        $headers = array_change_key_case($response->headers(), CASE_LOWER);
        $present = [];
        $missing = [];

        foreach (self::SECURITY_HEADERS as $header) {
            $value = $headers[$header] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $missing[] = $header;
            } else {
                $present[$header] = is_array($value) ? ($value[0] ?? '') : (string) $value;
            }
        }

        foreach ($missing as $header) {
            // Defense-in-depth — Medium max (not High).
            $severity = in_array($header, ['strict-transport-security', 'content-security-policy'], true)
                ? FindingSeverity::Medium
                : FindingSeverity::Low;

            $findings[] = new ScannerFinding(
                title: 'Missing security header: '.$header,
                severity: $severity,
                source: 'http',
                category: 'headers',
                description: "Response lacks `{$header}`. Add it at the edge (CDN) or app layer.",
                evidence: ['url' => $url, 'header' => $header],
                fingerprint: 'header-missing-'.$asset->id.'-'.$header,
            );
        }

        foreach ($present as $header => $value) {
            $findings[] = new ScannerFinding(
                title: 'Security header present: '.$header,
                severity: FindingSeverity::Low,
                source: 'http',
                category: 'passed',
                description: $value,
                evidence: ['url' => $url, 'header' => $header, 'value' => $value],
                fingerprint: 'header-ok-'.$asset->id.'-'.$header,
            );
        }

        return $findings;
    }

    /**
     * @return list<ScannerFinding>
     */
    private function fromCors(Asset $asset, string $base): array
    {
        $origin = 'https://evil-hackly-probe.example';

        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    'User-Agent' => 'HacklySoftScanner/1.0',
                    'Origin' => $origin,
                ])
                ->withOptions(['allow_redirects' => false])
                ->get($base.'/');
        } catch (\Throwable) {
            return [];
        }

        $acao = (string) $response->header('Access-Control-Allow-Origin');
        $acac = strtolower((string) $response->header('Access-Control-Allow-Credentials'));

        if ($acao === '') {
            return [
                new ScannerFinding(
                    title: 'CORS does not reflect arbitrary Origin',
                    severity: FindingSeverity::Low,
                    source: 'http',
                    category: 'passed',
                    description: 'No Access-Control-Allow-Origin for a forged Origin header.',
                    evidence: ['origin' => $origin],
                    fingerprint: 'cors-ok-'.$asset->id,
                ),
            ];
        }

        $findings = [];

        if ($acao === '*' && $acac === 'true') {
            $findings[] = new ScannerFinding(
                title: 'CORS misconfiguration: ACAO=* with credentials',
                severity: FindingSeverity::High,
                source: 'http',
                category: 'cors',
                description: 'Access-Control-Allow-Origin: * combined with Allow-Credentials: true is invalid and often indicates a broken CORS policy.',
                evidence: ['acao' => $acao, 'acac' => $acac],
                fingerprint: 'cors-star-credentials-'.$asset->id,
            );
        } elseif ($acao === $origin || strcasecmp($acao, $origin) === 0) {
            $findings[] = new ScannerFinding(
                title: 'CORS reflects arbitrary Origin',
                severity: $acac === 'true' ? FindingSeverity::High : FindingSeverity::Medium,
                source: 'http',
                category: 'cors',
                description: 'Server reflected a forged Origin in Access-Control-Allow-Origin'
                    .($acac === 'true' ? ' with credentials — cross-site credentialed reads may be possible.' : '.'),
                evidence: ['origin' => $origin, 'acao' => $acao, 'acac' => $acac],
                fingerprint: 'cors-reflect-'.$asset->id,
            );
        }

        return $findings;
    }

    /**
     * @return list<ScannerFinding>
     */
    private function fromHttpMethods(Asset $asset, string $base): array
    {
        try {
            $response = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'HacklySoftScanner/1.0'])
                ->withOptions(['allow_redirects' => false])
                ->send('OPTIONS', $base.'/');
        } catch (\Throwable) {
            return [];
        }

        $allow = (string) ($response->header('Allow') ?: $response->header('Access-Control-Allow-Methods'));
        if ($allow === '') {
            return [];
        }

        $methods = array_map('strtoupper', array_map('trim', explode(',', $allow)));
        $risky = array_values(array_intersect($methods, ['TRACE', 'TRACK']));

        if ($risky === []) {
            return [
                new ScannerFinding(
                    title: 'Dangerous HTTP methods not advertised',
                    severity: FindingSeverity::Low,
                    source: 'http',
                    category: 'passed',
                    description: 'OPTIONS Allow/Access-Control-Allow-Methods: '.$allow,
                    evidence: ['allow' => $allow],
                    fingerprint: 'http-methods-ok-'.$asset->id,
                ),
            ];
        }

        return [
            new ScannerFinding(
                title: 'Dangerous HTTP methods enabled: '.implode(', ', $risky),
                severity: FindingSeverity::Medium,
                source: 'http',
                category: 'http_methods',
                description: 'TRACE/TRACK can aid cross-site tracing attacks. Disable them at the web server.',
                evidence: ['allow' => $allow, 'risky' => $risky],
                fingerprint: 'http-methods-risky-'.$asset->id,
            ),
        ];
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
            || (str_contains($lower, 'laravel') && str_contains($lower, 'stack trace') && $response->status() >= 500)
        ) {
            $findings[] = new ScannerFinding(
                title: 'APP_DEBUG / Ignition error page exposed',
                severity: FindingSeverity::High,
                source: 'http',
                category: 'laravel_debug',
                description: 'Error page content suggests Laravel debug mode (Ignition/Whoops) is enabled in production. This commonly leaks env secrets, paths, and query data.',
                evidence: [
                    'url' => $url,
                    'context' => $context,
                    'status' => $response->status(),
                    'signals' => array_values(array_filter([
                        str_contains($lower, 'ignition') ? 'ignition' : null,
                        str_contains($lower, 'whoops') ? 'whoops' : null,
                        str_contains($lower, 'flareapp') ? 'flare' : null,
                        str_contains($lower, 'stack trace') ? 'stack_trace' : null,
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
     * @return list<array{name: string, httponly: bool, secure: bool, samesite: ?string, domain: ?string, attrs: string}>
     */
    private function parseCookies(Response $response): array
    {
        $raw = $response->headers()['Set-Cookie'] ?? $response->headers()['set-cookie'] ?? [];

        if (is_string($raw)) {
            $raw = [$raw];
        }

        if (! is_array($raw)) {
            return [];
        }

        $cookies = [];

        foreach ($raw as $cookie) {
            if (! is_string($cookie) || $cookie === '') {
                continue;
            }

            $parts = array_map('trim', explode(';', $cookie));
            $nameValue = array_shift($parts) ?? '';
            $name = trim((string) strtok($nameValue, '='));

            if ($name === '') {
                continue;
            }

            $attrsLower = strtolower(implode(';', $parts));
            $sameSite = null;
            $domain = null;

            foreach ($parts as $part) {
                if (preg_match('/^samesite=(.+)$/i', $part, $m)) {
                    $sameSite = trim($m[1]);
                }
                if (preg_match('/^domain=(.+)$/i', $part, $m)) {
                    $domain = trim($m[1]);
                }
            }

            $cookies[] = [
                'name' => $name,
                'httponly' => str_contains($attrsLower, 'httponly'),
                'secure' => preg_match('/(?:^|;)\s*secure(?:;|$)/i', ';'.$attrsLower.';') === 1,
                'samesite' => $sameSite,
                'domain' => $domain,
                'attrs' => implode('; ', $parts),
            ];
        }

        return $cookies;
    }
}
