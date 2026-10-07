<?php

namespace App\Domain\RepoScanning\Scanners;

use App\Domain\RepoScanning\DTO\RawFinding;
use App\Domain\Scanning\DTO\BinaryResult;
use App\Enums\FindingSeverity;
use App\Enums\RepoScanTaskType;
use App\Models\RepoScan;
use App\Models\RepoScanTask;
use App\Models\Repository;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Dependency hygiene from `composer outdated --locked` (reads composer.lock, no install needed).
 *
 * Deliberately low-noise — only direct production dependencies are considered:
 *  - abandoned packages                                  → Medium
 *  - laravel/framework two or more majors behind latest  → Medium (out of security support)
 *  - any other package at least one major behind         → Low, one finding per package
 *  - semver-compatible updates                           → a single aggregated Low finding
 */
class ComposerOutdatedScanner extends AbstractRepoScanner
{
    public function type(): RepoScanTaskType
    {
        return RepoScanTaskType::ComposerOutdated;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('hackly.repo.composer.timeout', 300);
    }

    public function supports(Repository $repository, RepoScan $scan): bool
    {
        $workspace = $scan->workspace_path;

        return is_string($workspace)
            && is_file($workspace.'/composer.json')
            && is_file($workspace.'/composer.lock');
    }

    public function buildCommand(Repository $repository, RepoScan $scan, RepoScanTask $task, string $outputPath): array
    {
        // In-process scanner.
        return ['true'];
    }

    public function runInProcess(Repository $repository, RepoScan $scan, RepoScanTask $task): ?array
    {
        $workspace = $this->workspace($scan);
        $composer = $this->binary('composer') ?: 'composer';

        // Plugins and scripts of an untrusted repository must never run.
        $result = Process::path($workspace)
            ->timeout($this->timeoutSeconds())
            ->env(['COMPOSER_NO_INTERACTION' => '1'])
            ->run([$composer, 'outdated', '--locked', '--direct', '--no-dev', '--format=json', '--no-plugins', '--no-scripts', '--no-interaction']);

        $report = json_decode($result->output(), true);

        if (! is_array($report)) {
            $error = trim($result->errorOutput()) ?: 'no JSON output';

            throw new RuntimeException('composer outdated failed: '.str($error)->limit(300));
        }

        return static::findingsFromReport($report);
    }

    public function parse(Repository $repository, RepoScan $scan, RepoScanTask $task, BinaryResult $result): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $report  decoded `composer outdated --format=json` output
     * @return list<RawFinding>
     */
    public static function findingsFromReport(array $report): array
    {
        $packages = $report['locked'] ?? $report['installed'] ?? [];

        if (! is_array($packages)) {
            return [];
        }

        $findings = [];
        $compatible = [];

        foreach ($packages as $package) {
            if (! is_array($package) || empty($package['name']) || empty($package['version'])) {
                continue;
            }

            $name = (string) $package['name'];
            $version = (string) $package['version'];
            $latest = (string) ($package['latest'] ?? '');
            $status = (string) ($package['latest-status'] ?? '');
            $abandoned = $package['abandoned'] ?? false;

            if ($abandoned !== false && $abandoned !== null) {
                $replacement = is_string($abandoned) && $abandoned !== '' ? $abandoned : null;

                $findings[] = new RawFinding(
                    title: "Abandoned package: {$name}",
                    severity: FindingSeverity::Medium,
                    source: 'composer-outdated',
                    category: 'dependency_health',
                    description: $replacement
                        ? "{$name} is no longer maintained; its author recommends {$replacement}. Abandoned packages stop receiving security fixes."
                        : "{$name} is no longer maintained and will not receive security fixes. Plan a replacement.",
                    evidence: array_filter([
                        'package' => $name,
                        'package_version' => $version,
                        'replacement' => $replacement,
                    ]),
                    package: $name,
                    packageVersion: $version,
                    file: 'composer.lock',
                    tools: ['composer-outdated'],
                    dedupeKey: 'composer-abandoned|'.strtolower($name),
                );
            }

            if ($latest === '' || $status === 'up-to-date') {
                continue;
            }

            $gap = static::majorGap($version, $latest);

            if ($status === 'update-possible' && $gap >= 1) {
                $isFramework = $name === 'laravel/framework';
                $outOfSupport = $isFramework && $gap >= 2;

                $findings[] = new RawFinding(
                    title: $outOfSupport
                        ? sprintf('Laravel %s is out of security support (latest %s)', static::major($version), static::major($latest))
                        : sprintf('%s is %d major %s behind (%s → %s)', $name, $gap, $gap === 1 ? 'version' : 'versions', ltrim($version, 'v'), ltrim($latest, 'v')),
                    severity: $outOfSupport ? FindingSeverity::Medium : FindingSeverity::Low,
                    source: 'composer-outdated',
                    category: 'dependency_health',
                    description: $outOfSupport
                        ? 'Laravel only ships security fixes for the latest two major versions. Upgrade the framework to keep receiving patches.'
                        : 'Major upgrades pile up: older majors stop receiving fixes and upgrades get harder the longer they wait.',
                    evidence: [
                        'package' => $name,
                        'package_version' => $version,
                        'latest' => $latest,
                        'majors_behind' => $gap,
                    ],
                    package: $name,
                    packageVersion: $version,
                    file: 'composer.lock',
                    tools: ['composer-outdated'],
                    dedupeKey: 'composer-outdated-major|'.strtolower($name),
                );

                continue;
            }

            $compatible[] = $name.' '.ltrim($version, 'v').' → '.ltrim($latest, 'v');
        }

        if ($compatible !== []) {
            $count = count($compatible);

            $findings[] = new RawFinding(
                title: $count === 1
                    ? '1 direct dependency has a compatible update'
                    : "{$count} direct dependencies have compatible updates",
                severity: FindingSeverity::Low,
                source: 'composer-outdated',
                category: 'dependency_health',
                description: 'Updates within your version constraints. Running `composer update` regularly keeps bug and security fixes flowing.',
                evidence: [
                    'records' => $compatible,
                ],
                file: 'composer.lock',
                tools: ['composer-outdated'],
                dedupeKey: 'composer-outdated-compatible',
            );
        }

        return $findings;
    }

    public static function majorGap(string $current, string $latest): int
    {
        [$currentMajor, $currentMinor] = static::versionParts($current);
        [$latestMajor, $latestMinor] = static::versionParts($latest);

        // 0.x packages treat the minor version as the breaking one.
        if ($currentMajor === 0 && $latestMajor === 0) {
            return max(0, $latestMinor - $currentMinor);
        }

        return max(0, $latestMajor - $currentMajor);
    }

    private static function major(string $version): int
    {
        return static::versionParts($version)[0];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function versionParts(string $version): array
    {
        $clean = ltrim(trim($version), 'vV');

        if (! preg_match('/^(\d+)(?:\.(\d+))?/', $clean, $m)) {
            return [0, 0];
        }

        return [(int) $m[1], (int) ($m[2] ?? 0)];
    }
}
