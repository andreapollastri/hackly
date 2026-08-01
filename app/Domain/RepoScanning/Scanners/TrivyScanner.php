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

class TrivyScanner extends AbstractRepoScanner
{
    public function type(): RepoScanTaskType
    {
        return RepoScanTaskType::TrivySca;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('hackly.repo.trivy.timeout', 600);
    }

    public function supports(Repository $repository, RepoScan $scan): bool
    {
        return $this->binaryAvailable('trivy');
    }

    public function buildCommand(Repository $repository, RepoScan $scan, RepoScanTask $task, string $outputPath): array
    {
        $this->ensureOutputDir($outputPath);
        $workspace = $this->workspace($scan);
        $trivy = $this->binary('trivy');

        if (! $this->binaryAvailable('trivy')) {
            throw new RuntimeException('Trivy binary is not available. Run scripts/install-repo-scanners.sh');
        }

        $jsonOut = $outputPath.'.json';

        return [
            $trivy, 'fs',
            '--scanners', 'vuln',
            '--format', 'json',
            '--output', $jsonOut,
            '--quiet',
            '--skip-dirs', 'node_modules',
            '--skip-dirs', 'storage',
            $workspace,
        ];
    }

    public function parse(Repository $repository, RepoScan $scan, RepoScanTask $task, BinaryResult $result): array
    {
        $jsonOut = ($result->outputPath ?? '').'.json';

        if (! is_file($jsonOut)) {
            if ($result->exitCode !== 0) {
                throw new RuntimeException('Trivy failed: '.substr($result->stderr ?: $result->stdout, 0, 1000));
            }

            return [];
        }

        $data = json_decode((string) file_get_contents($jsonOut), true);
        $results = is_array($data['Results'] ?? null) ? $data['Results'] : [];
        $findings = [];

        foreach ($results as $resultRow) {
            if (! is_array($resultRow)) {
                continue;
            }

            $target = (string) ($resultRow['Target'] ?? '');
            // Prefer PHP/composer ecosystem signal.
            $isPhpTarget = str_contains(strtolower($target), 'composer')
                || str_ends_with(strtolower($target), 'composer.lock')
                || str_ends_with(strtolower($target), 'composer.json');

            foreach ($resultRow['Vulnerabilities'] ?? [] as $vuln) {
                if (! is_array($vuln)) {
                    continue;
                }

                $pkg = (string) ($vuln['PkgName'] ?? '');
                $cve = (string) ($vuln['VulnerabilityID'] ?? '');
                $severity = FindingSeverity::normalize((string) ($vuln['Severity'] ?? 'UNKNOWN'));
                $title = (string) ($vuln['Title'] ?? ($cve !== '' ? $cve : 'Dependency vulnerability'));
                $installed = (string) ($vuln['InstalledVersion'] ?? '');

                if (! $isPhpTarget && $pkg !== '' && ! str_contains($pkg, '/')) {
                    // Skip obvious non-PHP ecosystem noise when target isn't composer.
                    continue;
                }

                $findings[] = new RawFinding(
                    title: $title.($pkg !== '' ? " ({$pkg})" : ''),
                    severity: $severity,
                    source: 'trivy',
                    category: 'sca',
                    cve: $cve !== '' ? $cve : null,
                    description: (string) ($vuln['Description'] ?? $title),
                    evidence: [
                        'package' => $pkg,
                        'version' => $installed,
                        'fixed_version' => $vuln['FixedVersion'] ?? null,
                        'target' => $target,
                        'tool' => 'trivy',
                    ],
                    package: $pkg !== '' ? $pkg : null,
                    packageVersion: $installed !== '' ? $installed : null,
                    file: $target !== '' ? basename($target) : 'composer.lock',
                    tools: ['trivy'],
                );
            }
        }

        return $findings;
    }
}
