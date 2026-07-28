<?php

namespace App\Domain\Scanning\Scanners;

use App\Domain\Scanning\DTO\BinaryResult;
use App\Domain\Scanning\DTO\ScannerFinding;
use App\Domain\Scanning\Services\BinaryRunner;
use App\Enums\FindingSeverity;
use App\Enums\ScanTaskType;
use App\Models\Asset;
use App\Models\ScanTask;
use RuntimeException;

class NucleiScanner extends AbstractScanner
{
    public function type(): ScanTaskType
    {
        return ScanTaskType::NucleiOwasp;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('hackly.nuclei.timeout', 600);
    }

    public function estimateCost(): int
    {
        return 3;
    }

    public function buildCommand(Asset $asset, ScanTask $task, string $outputPath): array
    {
        $this->ensureOutputDir($outputPath);

        $nuclei = $this->binary('nuclei');
        $runner = app(BinaryRunner::class);

        if (! $runner->binaryExists($nuclei)) {
            throw new RuntimeException('Nuclei binary is not available. Install nuclei or skip nuclei_owasp tasks.');
        }

        $jsonl = $outputPath.'.jsonl';
        $command = [
            $nuclei,
            '-u',
            $asset->httpBaseUrl(),
            '-tags',
            'owasp,cve,misconfig,exposure,vuln',
            '-severity',
            'info,low,medium,high,critical',
            '-rate-limit',
            (string) config('hackly.nuclei.rate_limit', 30),
            '-c',
            (string) config('hackly.nuclei.concurrency', 5),
            '-silent',
            '-jsonl',
            '-o',
            $jsonl,
        ];

        $templates = config('hackly.nuclei.templates_path');
        if (filled($templates)) {
            $command[] = '-t';
            $command[] = $templates;
        }

        return $command;
    }

    public function parse(Asset $asset, ScanTask $task, BinaryResult $result): array
    {
        $jsonl = ($result->outputPath ?? '').'.jsonl';
        $findings = [];

        if (! is_file($jsonl)) {
            if ($result->exitCode !== 0) {
                throw new RuntimeException('Nuclei failed: '.substr($result->stderr ?: $result->stdout, 0, 1000));
            }

            return [];
        }

        foreach (file($jsonl, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (! is_array($row)) {
                continue;
            }

            $templateId = (string) ($row['template-id'] ?? $row['templateID'] ?? 'unknown');
            $info = is_array($row['info'] ?? null) ? $row['info'] : [];
            $name = (string) ($info['name'] ?? $templateId);
            $severity = $this->mapSeverity((string) ($info['severity'] ?? 'info'));
            $cve = null;

            foreach (($info['classification']['cve-id'] ?? []) as $cveId) {
                $cve = (string) $cveId;
                break;
            }

            $findings[] = new ScannerFinding(
                title: $name,
                severity: $severity,
                source: 'nuclei',
                category: (string) (($info['tags'][0] ?? null) ?: 'owasp'),
                cve: $cve,
                description: (string) ($info['description'] ?? ''),
                evidence: [
                    'template' => $templateId,
                    'matched_at' => $row['matched-at'] ?? null,
                    'host' => $row['host'] ?? null,
                    'type' => $row['type'] ?? null,
                    'curl_command' => isset($row['curl-command']) ? '[redacted]' : null,
                ],
                fingerprint: 'nuclei-'.$asset->id.'-'.$templateId.'-'.md5((string) ($row['matched-at'] ?? $name)),
            );
        }

        return $findings;
    }

    private function mapSeverity(string $severity): FindingSeverity
    {
        return FindingSeverity::normalize($severity);
    }
}
