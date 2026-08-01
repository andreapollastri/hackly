<?php

namespace App\Domain\RepoScanning\Scanners;

use App\Domain\RepoScanning\DTO\RawFinding;
use App\Domain\Scanning\DTO\BinaryResult;
use App\Enums\FindingSeverity;
use App\Enums\RepoScanTaskType;
use App\Models\RepoScan;
use App\Models\RepoScanTask;
use App\Models\Repository;
use RuntimeException;

class SemgrepScanner extends AbstractRepoScanner
{
    public function type(): RepoScanTaskType
    {
        return RepoScanTaskType::SemgrepSast;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('hackly.repo.semgrep.timeout', 600);
    }

    public function supports(Repository $repository, RepoScan $scan): bool
    {
        return $this->binaryAvailable('semgrep');
    }

    public function buildCommand(Repository $repository, RepoScan $scan, RepoScanTask $task, string $outputPath): array
    {
        $this->ensureOutputDir($outputPath);
        $workspace = $this->workspace($scan);
        $semgrep = $this->binary('semgrep');

        if (! $this->binaryAvailable('semgrep')) {
            throw new RuntimeException('Semgrep binary is not available. Run scripts/install-repo-scanners.sh');
        }

        $config = (string) config('hackly.repo.semgrep.config', 'p/php');
        $jsonOut = $outputPath.'.json';

        return [
            $semgrep, 'scan',
            '--config', $config,
            '--json',
            '--quiet',
            '--metrics', 'off',
            '--exclude', 'vendor',
            '--exclude', 'node_modules',
            '--exclude', 'storage',
            '--output', $jsonOut,
            $workspace,
        ];
    }

    public function parse(Repository $repository, RepoScan $scan, RepoScanTask $task, BinaryResult $result): array
    {
        $jsonOut = ($result->outputPath ?? '').'.json';

        if (! is_file($jsonOut)) {
            if ($result->exitCode > 1) {
                throw new RuntimeException('Semgrep failed: '.substr($result->stderr ?: $result->stdout, 0, 1000));
            }

            return [];
        }

        $data = json_decode((string) file_get_contents($jsonOut), true);
        $results = is_array($data['results'] ?? null) ? $data['results'] : [];
        $findings = [];

        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }

            $checkId = (string) ($row['check_id'] ?? 'semgrep');
            $path = (string) ($row['path'] ?? '');
            $start = is_array($row['start'] ?? null) ? $row['start'] : [];
            $line = isset($start['line']) ? (int) $start['line'] : null;
            $extra = is_array($row['extra'] ?? null) ? $row['extra'] : [];
            $severity = FindingSeverity::normalize((string) ($extra['severity'] ?? 'WARNING'));
            $message = (string) ($extra['message'] ?? $checkId);

            // Keep PHP/Laravel signal; drop unrelated languages if any slipped through.
            if ($path !== '' && ! preg_match('/\.(php|blade\.php|env|yml|yaml|json)$/i', $path)) {
                continue;
            }

            $findings[] = new RawFinding(
                title: $message,
                severity: $severity,
                source: 'semgrep',
                category: 'sast',
                description: $message,
                evidence: [
                    'file' => $path,
                    'line' => $line,
                    'rule_id' => $checkId,
                    'tool' => 'semgrep',
                ],
                file: $path !== '' ? $this->relativePath($scan, $path) : null,
                line: $line,
                ruleId: $checkId,
                tools: ['semgrep'],
            );
        }

        return $findings;
    }

    private function relativePath(RepoScan $scan, string $path): string
    {
        $workspace = rtrim((string) $scan->workspace_path, '/').'/';

        if (str_starts_with($path, $workspace)) {
            return substr($path, strlen($workspace));
        }

        return ltrim($path, '/');
    }
}
