<?php

namespace App\Domain\RepoScanning\Scanners;

use App\Domain\RepoScanning\DTO\RawFinding;
use App\Domain\Scanning\DTO\BinaryResult;
use App\Enums\FindingSeverity;
use App\Enums\RepoScanTaskType;
use App\Models\RepoScan;
use App\Models\RepoScanTask;
use App\Models\Repository;

class LaravelPhpAuditScanner extends AbstractRepoScanner
{
    public function type(): RepoScanTaskType
    {
        return RepoScanTaskType::LaravelPhpAudit;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('hackly.repo.laravel_audit.timeout', 120);
    }

    public function supports(Repository $repository, RepoScan $scan): bool
    {
        $workspace = $scan->workspace_path;

        return is_string($workspace)
            && (is_file($workspace.'/artisan') || is_file($workspace.'/composer.json'));
    }

    public function buildCommand(Repository $repository, RepoScan $scan, RepoScanTask $task, string $outputPath): array
    {
        return ['true'];
    }

    public function runInProcess(Repository $repository, RepoScan $scan, RepoScanTask $task): ?array
    {
        $workspace = $this->workspace($scan);
        $findings = [];

        $composer = $this->readJson($workspace.'/composer.json');
        $isLaravel = isset($composer['require']['laravel/framework']) || is_file($workspace.'/artisan');

        if (! $isLaravel) {
            $findings[] = new RawFinding(
                title: 'Repository does not look like a Laravel app',
                severity: FindingSeverity::Low,
                source: 'hackly-laravel-audit',
                category: 'passed',
                description: 'Skipped deep Laravel checks — no artisan/laravel/framework detected.',
                evidence: ['note' => 'not_laravel'],
                tools: ['hackly-laravel-audit'],
                dedupeKey: 'laravel-audit|not-laravel',
            );

            return $findings;
        }

        if (is_file($workspace.'/.env')) {
            $findings[] = new RawFinding(
                title: 'Committed .env file',
                severity: FindingSeverity::High,
                source: 'hackly-laravel-audit',
                category: 'secret',
                description: 'A .env file is present in the repository workspace. Environment secrets must never be committed.',
                evidence: ['file' => '.env', 'tool' => 'hackly-laravel-audit'],
                file: '.env',
                ruleId: 'laravel.env.committed',
                tools: ['hackly-laravel-audit'],
            );

            $env = (string) file_get_contents($workspace.'/.env');
            if (preg_match('/^APP_DEBUG\\s*=\\s*true/mi', $env)) {
                $findings[] = $this->finding(
                    'APP_DEBUG=true in committed .env',
                    FindingSeverity::High,
                    'laravel.debug.env',
                    '.env',
                    'Debug mode enabled in a committed environment file.',
                );
            }

            if (preg_match('/^APP_KEY\\s*=\\s*base64:/mi', $env)) {
                $findings[] = $this->finding(
                    'APP_KEY present in committed .env',
                    FindingSeverity::High,
                    'laravel.app_key.env',
                    '.env',
                    'Application key is committed and can decrypt cookies/sessions.',
                );
            }
        }

        $require = $composer['require'] ?? [];
        $requireDev = $composer['require-dev'] ?? [];

        foreach (['barryvdh/laravel-debugbar', 'laravel/telescope', 'filp/whoops'] as $pkg) {
            if (isset($require[$pkg])) {
                $findings[] = $this->finding(
                    "{$pkg} required in production composer dependencies",
                    FindingSeverity::High,
                    'laravel.prod.debug_package.'.$pkg,
                    'composer.json',
                    "Package {$pkg} should typically live in require-dev, not require.",
                    $pkg,
                );
            }
        }

        if (isset($require['nunomaduro/collision']) || isset($require['laravel/pail'])) {
            $pkg = isset($require['nunomaduro/collision']) ? 'nunomaduro/collision' : 'laravel/pail';
            $findings[] = $this->finding(
                "Dev tooling {$pkg} in production require",
                FindingSeverity::Medium,
                'laravel.prod.devtool.'.$pkg,
                'composer.json',
                "{$pkg} is usually a development dependency.",
                $pkg,
            );
        }

        // Dangerous public paths / leftover install artifacts.
        foreach ([
            'public/storage' => 'public/storage exists in repo (should be a deploy-time symlink)',
            'storage/logs/laravel.log' => 'Application log committed',
            'public/phpinfo.php' => 'phpinfo.php exposed in public/',
            'public/test.php' => 'test.php exposed in public/',
            'server.php' => 'Legacy server.php present',
        ] as $rel => $title) {
            if (file_exists($workspace.'/'.$rel)) {
                $findings[] = $this->finding(
                    $title,
                    str_contains($rel, 'phpinfo') || str_contains($rel, 'log') ? FindingSeverity::High : FindingSeverity::Medium,
                    'laravel.artifact.'.sha1($rel),
                    $rel,
                    $title,
                );
            }
        }

        // Routes that often expose admin tooling without auth in misconfigured apps.
        $routeFiles = [];
        foreach (['routes/web.php', 'routes/api.php', 'routes/console.php'] as $routeFile) {
            if (is_file($workspace.'/'.$routeFile)) {
                $routeFiles[$routeFile] = (string) file_get_contents($workspace.'/'.$routeFile);
            }
        }

        $dangerousRouteHints = [
            'Telescope::' => 'Laravel Telescope routes referenced',
            'Horizon::' => 'Laravel Horizon routes referenced',
            'Pulse::' => 'Laravel Pulse routes referenced',
            '/_ignition' => 'Ignition endpoint referenced in routes',
        ];

        foreach ($routeFiles as $file => $contents) {
            foreach ($dangerousRouteHints as $needle => $title) {
                if (str_contains($contents, $needle)) {
                    $findings[] = $this->finding(
                        $title,
                        FindingSeverity::Medium,
                        'laravel.routes.'.$needle,
                        $file,
                        "{$title} — verify authentication middleware in production.",
                    );
                }
            }
        }

        // Mass-assignment / raw DB patterns (lightweight heuristics).
        $appDir = $workspace.'/app';
        if (is_dir($appDir)) {
            $dbRawHits = $this->grepPhp($appDir, '/DB::(raw|select|statement)\\s*\\(\\s*[\\\'"]\\s*(SELECT|INSERT|UPDATE|DELETE)/i', 15);
            foreach ($dbRawHits as $hit) {
                $findings[] = $this->finding(
                    'Raw SQL construction detected',
                    FindingSeverity::Medium,
                    'laravel.raw_sql',
                    $hit['file'],
                    'Possible SQL injection sink — review parameterization.',
                    null,
                    $hit['line'],
                );
            }

            $unscopedHits = $this->grepPhp($appDir, '/function\\s+getFillable\\s*\\([^)]*\\)\\s*\\{\\s*return\\s*\\[\\s*\\*[\\\'"]\\s*\\]/s*\\}/i', 10);
            foreach ($unscopedHits as $hit) {
                $findings[] = $this->finding(
                    'Model $fillable allows all attributes (*)',
                    FindingSeverity::High,
                    'laravel.mass_assignment',
                    $hit['file'],
                    'Mass assignment guard disabled.',
                    null,
                    $hit['line'],
                );
            }
        }

        // config/app.php debug default
        if (is_file($workspace.'/config/app.php')) {
            $config = (string) file_get_contents($workspace.'/config/app.php');
            if (preg_match("/['\"]debug['\"]\\s*=>\\s*true/", $config)) {
                $findings[] = $this->finding(
                    'config/app.php hardcodes debug=true',
                    FindingSeverity::High,
                    'laravel.config.debug',
                    'config/app.php',
                    'Debug should come from env and be false in production.',
                );
            }
        }

        if ($findings === []) {
            $findings[] = new RawFinding(
                title: 'Laravel PHP audit — no static issues',
                severity: FindingSeverity::Low,
                source: 'hackly-laravel-audit',
                category: 'passed',
                description: 'No high-signal Laravel static misconfigurations detected.',
                evidence: ['note' => 'clean'],
                tools: ['hackly-laravel-audit'],
                dedupeKey: 'laravel-audit|clean',
            );
        }

        // Silence unused require-dev notice
        unset($requireDev);

        return $findings;
    }

    public function parse(Repository $repository, RepoScan $scan, RepoScanTask $task, BinaryResult $result): array
    {
        return [];
    }

    private function finding(
        string $title,
        FindingSeverity $severity,
        string $ruleId,
        string $file,
        string $description,
        ?string $package = null,
        ?int $line = null,
    ): RawFinding {
        return new RawFinding(
            title: $title,
            severity: $severity,
            source: 'hackly-laravel-audit',
            category: 'laravel',
            description: $description,
            evidence: [
                'file' => $file,
                'line' => $line,
                'rule_id' => $ruleId,
                'tool' => 'hackly-laravel-audit',
            ],
            package: $package,
            file: $file,
            line: $line,
            ruleId: $ruleId,
            tools: ['hackly-laravel-audit'],
        );
    }

    /**
     * @return list<array{file: string, line: int}>
     */
    private function grepPhp(string $root, string $regex, int $limit): array
    {
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = @file_get_contents($file->getPathname());
            if (! is_string($contents)) {
                continue;
            }

            if (! preg_match($regex, $contents)) {
                continue;
            }

            $line = 1;
            foreach (explode("\n", $contents) as $index => $text) {
                if (preg_match($regex, $text)) {
                    $line = $index + 1;
                    break;
                }
            }

            $relative = $file->getPathname();
            $pos = strpos($relative, '/app/');
            if ($pos !== false) {
                $relative = substr($relative, $pos + 1);
            }

            $hits[] = ['file' => $relative, 'line' => $line];

            if (count($hits) >= $limit) {
                break;
            }
        }

        return $hits;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
