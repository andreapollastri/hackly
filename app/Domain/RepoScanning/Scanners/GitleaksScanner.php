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

class GitleaksScanner extends AbstractRepoScanner
{
    public function type(): RepoScanTaskType
    {
        return RepoScanTaskType::GitleaksSecrets;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('hackly.repo.gitleaks.timeout', 300);
    }

    public function supports(Repository $repository, RepoScan $scan): bool
    {
        return $this->binaryAvailable('gitleaks');
    }

    public function buildCommand(Repository $repository, RepoScan $scan, RepoScanTask $task, string $outputPath): array
    {
        $this->ensureOutputDir($outputPath);
        $workspace = $this->workspace($scan);
        $gitleaks = $this->binary('gitleaks');

        if (! $this->binaryAvailable('gitleaks')) {
            throw new RuntimeException('Gitleaks binary is not available. Run scripts/install-repo-scanners.sh');
        }

        $jsonOut = $outputPath.'.json';

        return [
            $gitleaks, 'detect',
            '--source', $workspace,
            '--report-format', 'json',
            '--report-path', $jsonOut,
            '--no-git',
            '--exit-code', '0',
        ];
    }

    public function parse(Repository $repository, RepoScan $scan, RepoScanTask $task, BinaryResult $result): array
    {
        $jsonOut = ($result->outputPath ?? '').'.json';

        if (! is_file($jsonOut)) {
            if ($result->exitCode !== 0) {
                throw new RuntimeException('Gitleaks failed: '.substr($result->stderr ?: $result->stdout, 0, 1000));
            }

            return [];
        }

        $rows = json_decode((string) file_get_contents($jsonOut), true);

        if (! is_array($rows)) {
            return [];
        }

        $findings = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rule = (string) ($row['RuleID'] ?? 'secret');
            $file = (string) ($row['File'] ?? '');
            $secret = (string) ($row['Secret'] ?? $row['Match'] ?? '');
            $line = isset($row['StartLine']) ? (int) $row['StartLine'] : null;
            $redacted = $this->redact($secret);

            $findings[] = new RawFinding(
                title: 'Secret detected: '.$rule,
                severity: FindingSeverity::High,
                source: 'gitleaks',
                category: 'secret',
                description: 'Potential secret committed in repository (rule '.$rule.').',
                evidence: [
                    'file' => $file,
                    'line' => $line,
                    'rule_id' => $rule,
                    'preview' => $redacted,
                    'tool' => 'gitleaks',
                ],
                file: $file !== '' ? $file : null,
                line: $line,
                ruleId: $rule,
                tools: ['gitleaks'],
                dedupeKey: 'secret|'.$rule.'|'.$file.'|'.sha1($redacted),
            );
        }

        return $findings;
    }

    private function redact(string $secret): string
    {
        $secret = trim($secret);

        if (strlen($secret) <= 8) {
            return '********';
        }

        return substr($secret, 0, 4).str_repeat('*', max(4, strlen($secret) - 8)).substr($secret, -4);
    }
}
