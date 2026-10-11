<?php

namespace App\Domain\Scanning\Scanners;

use App\Domain\Scanning\DTO\BinaryResult;
use App\Domain\Scanning\DTO\ScannerFinding;
use App\Domain\Scanning\Services\BinaryRunner;
use App\Enums\FindingSeverity;
use App\Enums\PentestTechnology;
use App\Enums\ScanTaskType;
use App\Models\Asset;
use App\Models\ScanTask;
use RuntimeException;

class WpScanScanner extends AbstractScanner
{
    public function type(): ScanTaskType
    {
        return ScanTaskType::WpScan;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('hackly.wpscan.timeout', 900);
    }

    public function estimateCost(): int
    {
        return 3;
    }

    public function supports(Asset $asset): bool
    {
        return app(BinaryRunner::class)->binaryExists($this->binary('wpscan'));
    }

    public function buildCommand(Asset $asset, ScanTask $task, string $outputPath): array
    {
        $this->ensureOutputDir($outputPath);

        $binary = $this->binary('wpscan');

        if (! app(BinaryRunner::class)->binaryExists($binary)) {
            throw new RuntimeException('WPScan is not installed. Install wpscan or skip this task.');
        }

        return [$binary, ...$this->arguments($asset, $task, $outputPath.'.json')];
    }

    /**
     * @return list<string>
     */
    public function arguments(Asset $asset, ScanTask $task, string $jsonPath): array
    {
        $arguments = [
            '--url',
            $asset->httpBaseUrl(),
            '--format',
            'json',
            '--output',
            $jsonPath,
            '--no-update',
            '--random-user-agent',
            '--throttle',
            (string) config('hackly.wpscan.throttle_ms', 500),
            '--enumerate',
            (string) config('hackly.wpscan.enumerate', 'vp,vt,cb,dbe,u'),
        ];

        $token = trim((string) config('hackly.wpscan.api_token', ''));

        if ($token !== '') {
            $arguments[] = '--api-token';
            $arguments[] = $token;
        }

        if ($task->scan?->penetrationTest?->technology === PentestTechnology::Wordpress) {
            $arguments[] = '--force';
        }

        return $arguments;
    }

    public function parse(Asset $asset, ScanTask $task, BinaryResult $result): array
    {
        $path = ($result->outputPath ?? '').'.json';

        if (! is_file($path)) {
            return [];
        }

        $report = json_decode((string) file_get_contents($path), true);

        if (! is_array($report)) {
            return [];
        }

        $findings = [];

        foreach ($report['interesting_findings'] ?? [] as $index => $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $findings[] = $this->finding(
                $asset,
                (string) ($finding['to_s'] ?? $finding['type'] ?? 'WordPress finding'),
                FindingSeverity::Medium,
                'wpscan-interesting-'.$index,
                ['url' => $finding['url'] ?? null],
            );
        }

        $version = is_array($report['version'] ?? null) ? $report['version'] : [];

        foreach ($version['vulnerabilities'] ?? [] as $vulnerability) {
            $findings[] = $this->vulnerability($asset, 'WordPress '.(string) ($version['number'] ?? ''), $vulnerability);
        }

        foreach (['main_theme' => $report['main_theme'] ?? null] as $theme) {
            if (is_array($theme)) {
                foreach ($theme['vulnerabilities'] ?? [] as $vulnerability) {
                    $findings[] = $this->vulnerability($asset, 'Theme '.(string) ($theme['slug'] ?? ''), $vulnerability);
                }
            }
        }

        foreach (['plugins', 'themes'] as $section) {
            foreach ($report[$section] ?? [] as $slug => $component) {
                if (! is_array($component)) {
                    continue;
                }

                foreach ($component['vulnerabilities'] ?? [] as $vulnerability) {
                    $findings[] = $this->vulnerability($asset, (string) $slug, $vulnerability);
                }
            }
        }

        foreach ($report['users'] ?? [] as $index => $user) {
            if (! is_array($user)) {
                continue;
            }

            $name = (string) ($user['username'] ?? $user['slug'] ?? '');

            if ($name === '') {
                continue;
            }

            $findings[] = $this->finding(
                $asset,
                'WordPress user disclosed: '.$name,
                FindingSeverity::Low,
                'wpscan-user-'.sha1($name),
                ['username' => $name],
            );
        }

        if ($findings === [] && filled($report['scan_aborted'] ?? null)) {
            $findings[] = $this->finding(
                $asset,
                'WPScan stopped early',
                FindingSeverity::Low,
                'wpscan-aborted',
                ['reason' => substr((string) $report['scan_aborted'], 0, 240)],
            );
        }

        return array_slice($findings, 0, 80);
    }

    /**
     * @param  array<string, mixed>  $vulnerability
     */
    private function vulnerability(Asset $asset, string $component, array $vulnerability): ScannerFinding
    {
        $title = trim($component.' — '.(string) ($vulnerability['title'] ?? 'Known vulnerability'));
        $cves = $vulnerability['references']['cve'] ?? [];
        $cve = is_array($cves) && isset($cves[0]) ? 'CVE-'.ltrim((string) $cves[0], 'CVE-') : null;
        $score = $vulnerability['cvss']['score'] ?? null;

        $severity = match (true) {
            is_numeric($score) && (float) $score >= 7 => FindingSeverity::High,
            is_numeric($score) && (float) $score >= 4 => FindingSeverity::Medium,
            is_numeric($score) => FindingSeverity::Low,
            default => FindingSeverity::High,
        };

        return $this->finding($asset, $title, $severity, 'wpscan-vuln-'.sha1($title), [
            'fixed_in' => $vulnerability['fixed_in'] ?? null,
            'cve' => $cve,
        ], $cve);
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function finding(Asset $asset, string $title, FindingSeverity $severity, string $key, array $evidence, ?string $cve = null): ScannerFinding
    {
        return new ScannerFinding(
            title: $title,
            severity: $severity,
            source: 'wpscan',
            category: 'wordpress',
            cve: $cve,
            description: $title,
            evidence: $evidence + ['tool' => 'wpscan'],
            fingerprint: $key.'|'.$asset->id,
        );
    }
}
