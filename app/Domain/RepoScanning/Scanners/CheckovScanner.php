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

class CheckovScanner extends AbstractRepoScanner
{
    public function type(): RepoScanTaskType
    {
        return RepoScanTaskType::CheckovIac;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('hackly.repo.checkov.timeout', 600);
    }

    public function supports(Repository $repository, RepoScan $scan): bool
    {
        if (! $this->binaryAvailable('checkov')) {
            return false;
        }

        $workspace = $scan->workspace_path;

        if (! is_string($workspace) || ! is_dir($workspace)) {
            return false;
        }

        // Only run when IaC-ish files exist (Docker/K8s/Terraform/GitHub Actions).
        foreach (['Dockerfile', 'docker-compose.yml', 'docker-compose.yaml', '.github/workflows'] as $hint) {
            if (file_exists($workspace.'/'.$hint) || is_dir($workspace.'/'.$hint)) {
                return true;
            }
        }

        foreach (glob($workspace.'/*.{tf,yml,yaml}', GLOB_BRACE) ?: [] as $ignored) {
            return true;
        }

        return false;
    }

    public function buildCommand(Repository $repository, RepoScan $scan, RepoScanTask $task, string $outputPath): array
    {
        $this->ensureOutputDir($outputPath);
        $workspace = $this->workspace($scan);
        $checkov = $this->binary('checkov');

        if (! $this->binaryAvailable('checkov')) {
            throw new RuntimeException('Checkov binary is not available. Run scripts/install-repo-scanners.sh');
        }

        $jsonOut = $outputPath.'.json';

        return [
            $checkov,
            '-d', $workspace,
            '-o', 'json',
            '--output-file-path', dirname($jsonOut).'/',
            '--soft-fail',
            '--skip-path', 'vendor',
            '--skip-path', 'node_modules',
            '--compact',
        ];
    }

    public function parse(Repository $repository, RepoScan $scan, RepoScanTask $task, BinaryResult $result): array
    {
        $dir = dirname((string) $result->outputPath);
        $candidates = [
            $dir.'/results_json.json',
            $dir.'/checkov_results.json',
            ($result->outputPath ?? '').'.json',
        ];

        $data = null;

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $data = json_decode((string) file_get_contents($candidate), true);
                break;
            }
        }

        if ($data === null && filled($result->stdout)) {
            $data = json_decode($result->stdout, true);
        }

        if (! is_array($data)) {
            return [];
        }

        $failed = [];

        if (isset($data['results']['failed_checks']) && is_array($data['results']['failed_checks'])) {
            $failed = $data['results']['failed_checks'];
        } elseif (array_is_list($data)) {
            foreach ($data as $block) {
                if (is_array($block) && isset($block['results']['failed_checks'])) {
                    $failed = array_merge($failed, $block['results']['failed_checks']);
                }
            }
        }

        $findings = [];

        foreach ($failed as $row) {
            if (! is_array($row)) {
                continue;
            }

            $checkId = (string) ($row['check_id'] ?? 'CKV');
            $name = (string) ($row['check_name'] ?? $checkId);
            $file = (string) ($row['file_path'] ?? $row['repo_file_path'] ?? '');
            $severity = FindingSeverity::normalize((string) ($row['severity'] ?? 'MEDIUM'));

            $findings[] = new RawFinding(
                title: $name,
                severity: $severity,
                source: 'checkov',
                category: 'iac',
                description: $name,
                evidence: [
                    'file' => $file,
                    'rule_id' => $checkId,
                    'resource' => $row['resource'] ?? null,
                    'tool' => 'checkov',
                ],
                file: $file !== '' ? ltrim($file, '/') : null,
                ruleId: $checkId,
                tools: ['checkov'],
            );
        }

        return $findings;
    }
}
