<?php

namespace App\Domain\RepoScanning\Scanners;

use App\Domain\RepoScanning\DTO\RawFinding;
use App\Domain\Scanning\DTO\BinaryResult;
use App\Enums\FindingSeverity;
use App\Enums\RepoScanTaskType;
use App\Models\RepoScan;
use App\Models\RepoScanTask;
use App\Models\Repository;
use Checkpoint\Checks\CheckResult;
use Checkpoint\Scanner;
use Illuminate\Support\Str;

class CheckpointScanner extends AbstractRepoScanner
{
    /**
     * Display names of the catalogue shipped with Checkpoint 1.2.
     * Scanner::withDefaultChecks() also picks up any check added later.
     *
     * @var list<string>
     */
    public const CHECKS = [
        'Composer CVE Audit',
        'NPM CVE Audit',
        'Environment Configuration',
        '.gitignore Sensitive Files',
        'File Permissions',
        'Hardcoded Secrets',
        'SQL Injection Risks',
        'Mass Assignment Vulnerabilities',
        'XSS (Cross-Site Scripting) Risks',
        'CSRF Protection',
        'Open Redirect Risks',
        'Command Injection Risks',
        'Insecure Deserialization',
        'Debug Functions in Production Code',
        'Sensitive Data Exposure',
        'SSRF Risks',
        'TLS Certificate Verification',
        'CORS Configuration',
        'Package Freshness (Supply Chain)',
        'Supply Chain Tooling',
        'Path Traversal Risks',
        'Weak Cryptography',
        'Insecure RNG',
        'Session & Cookie Security',
        'EOL Versions',
        'Suspicious Vendor Autoload',
        'Outdated Composer Packages',
        'Abandoned Composer Packages',
        'Composer Configuration',
        'XML External Entity (XXE) Risks',
    ];

    public function type(): RepoScanTaskType
    {
        return RepoScanTaskType::Checkpoint;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('hackly.repo.checkpoint.timeout', 600);
    }

    public function buildCommand(Repository $repository, RepoScan $scan, RepoScanTask $task, string $outputPath): array
    {
        return ['true'];
    }

    public function runInProcess(Repository $repository, RepoScan $scan, RepoScanTask $task): ?array
    {
        return $this->findings($this->workspace($scan));
    }

    /**
     * @return list<RawFinding>
     */
    public function findings(string $workspace): array
    {
        $resolved = realpath($workspace);
        if (is_string($resolved)) {
            $workspace = $resolved;
        }

        $results = $this->isolateHostConfig($workspace, function () use ($workspace): array {
            return Scanner::withDefaultChecks($workspace)->run();
        });

        $findings = [];

        foreach ($results as $name => $result) {
            foreach ($this->actionableDetails($name, $result, $workspace) as $detail) {
                $findings[] = $this->finding($name, $result, $detail);
            }
        }

        if ($findings === []) {
            $findings[] = new RawFinding(
                title: 'Checkpoint — all checks passed',
                severity: FindingSeverity::Low,
                source: 'checkpoint',
                category: 'passed',
                description: count($results).' Checkpoint checks ran. Nothing failed or warned.',
                evidence: ['checks' => count($results), 'tool' => 'checkpoint'],
                tools: ['checkpoint'],
                dedupeKey: 'checkpoint|clean',
            );
        }

        return $findings;
    }

    public function parse(Repository $repository, RepoScan $scan, RepoScanTask $task, BinaryResult $result): array
    {
        return [];
    }

    /**
     * Environment Configuration reads the process config(), which on a Hackly
     * worker is Hackly itself. Overlay the target .env for that check, then
     * put the worker config back.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function isolateHostConfig(string $workspace, callable $callback): mixed
    {
        $keys = ['app.debug', 'app.env', 'app.key', 'app.url', 'session.secure', 'session.driver'];
        $previous = [];

        foreach ($keys as $key) {
            $previous[$key] = config($key);
        }

        $env = $this->readEnv($workspace.'/.env');
        $present = fn (string $name): bool => array_key_exists($name, $env);

        config([
            'app.debug' => $present('APP_DEBUG') ? filter_var($env['APP_DEBUG'], FILTER_VALIDATE_BOOL) : false,
            'app.env' => $env['APP_ENV'] ?? 'production',
            'app.key' => $env['APP_KEY'] ?? 'base64:'.base64_encode(random_bytes(32)),
            'app.url' => $env['APP_URL'] ?? 'https://repository.invalid',
            'session.secure' => $present('SESSION_SECURE_COOKIE')
                ? filter_var($env['SESSION_SECURE_COOKIE'], FILTER_VALIDATE_BOOL)
                : true,
            'session.driver' => $env['SESSION_DRIVER'] ?? 'file',
        ]);

        try {
            return $callback();
        } finally {
            config($previous);
        }
    }

    /**
     * @return list<string>
     */
    private function actionableDetails(string $name, CheckResult $result, string $workspace): array
    {
        if ($result->status === CheckResult::PASS) {
            return [];
        }

        $details = $result->details;

        if ($name === 'Environment Configuration' && ! is_file($workspace.'/.env')) {
            $details = array_values(array_filter(
                $details,
                fn (string $detail): bool => ! str_contains($detail, '.env file not found'),
            ));
        }

        if ($details === []) {
            return $result->status === CheckResult::FAIL ? [$result->message] : [];
        }

        return $details;
    }

    private function finding(string $name, CheckResult $result, string $detail): RawFinding
    {
        [$file, $line] = $this->locate($detail);
        $hash = $result->hashFor($name, $detail);
        $severity = $result->status === CheckResult::FAIL
            ? FindingSeverity::High
            : FindingSeverity::Medium;

        return new RawFinding(
            title: Str::limit($detail, 180),
            severity: $severity,
            source: 'checkpoint',
            category: $this->category($name),
            description: $name.' — '.$result->message,
            evidence: [
                'check' => $name,
                'status' => $result->status,
                'hash' => $hash,
                'detail' => $detail,
                'tool' => 'checkpoint',
            ],
            file: $file,
            line: $line,
            ruleId: 'checkpoint.'.Str::slug($name),
            tools: ['checkpoint'],
            dedupeKey: 'checkpoint|'.$name.'|'.$hash,
        );
    }

    private function category(string $name): string
    {
        return match ($name) {
            'Hardcoded Secrets', '.gitignore Sensitive Files' => 'secret',
            'Composer CVE Audit', 'NPM CVE Audit', 'Package Freshness (Supply Chain)', 'Composer Configuration', 'EOL Versions', 'Suspicious Vendor Autoload', 'Supply Chain Tooling' => 'sca',
            'Outdated Composer Packages', 'Abandoned Composer Packages' => 'dependency_health',
            'SQL Injection Risks', 'Command Injection Risks', 'Open Redirect Risks', 'Insecure Deserialization', 'SSRF Risks', 'TLS Certificate Verification', 'Path Traversal Risks', 'Weak Cryptography', 'Insecure RNG', 'XML External Entity (XXE) Risks' => 'sast',
            default => 'laravel',
        };
    }

    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function locate(string $detail): array
    {
        if (preg_match('/^([A-Za-z0-9_@.\/\\\\-]+):(\d+)\b/', $detail, $matches) === 1) {
            return [$matches[1], (int) $matches[2]];
        }

        if (preg_match('/^([A-Za-z0-9_@.\/\\\\-]+\.[A-Za-z0-9]+)\b/', $detail, $matches) === 1) {
            return [$matches[1], null];
        }

        return [null, null];
    }

    /**
     * @return array<string, string>
     */
    private function readEnv(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $vars = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $vars[trim($key)] = $value;
        }

        return $vars;
    }
}
