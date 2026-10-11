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

class NiktoScanner extends AbstractScanner
{
    public function type(): ScanTaskType
    {
        return ScanTaskType::Nikto;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('hackly.nikto.timeout', 900);
    }

    public function estimateCost(): int
    {
        return 3;
    }

    public function supports(Asset $asset): bool
    {
        return app(BinaryRunner::class)->binaryExists($this->binary('nikto'));
    }

    public function buildCommand(Asset $asset, ScanTask $task, string $outputPath): array
    {
        $this->ensureOutputDir($outputPath);

        $binary = $this->binary('nikto');

        if (! app(BinaryRunner::class)->binaryExists($binary)) {
            throw new RuntimeException('Nikto is not installed. Install nikto or skip this task.');
        }

        return [$binary, ...$this->arguments($asset, $outputPath.'.json')];
    }

    /**
     * @return list<string>
     */
    public function arguments(Asset $asset, string $jsonPath): array
    {
        return [
            '-h',
            $asset->httpBaseUrl(),
            '-Format',
            'json',
            '-output',
            $jsonPath,
            '-nointeractive',
            '-maxtime',
            (string) config('hackly.nikto.max_time', '600'),
        ];
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

        $rows = $report['vulnerabilities'] ?? $report;

        if (! array_is_list($rows)) {
            return [];
        }

        $findings = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $message = trim((string) ($row['msg'] ?? $row['message'] ?? ''));

            if ($message === '') {
                continue;
            }

            $id = (string) ($row['id'] ?? sha1($message));
            $lower = strtolower($message);
            $severity = str_contains($lower, 'osvdb-0') || str_contains($lower, 'server banner') || str_contains($lower, 'allowed http methods')
                ? FindingSeverity::Low
                : FindingSeverity::Medium;

            $findings[] = new ScannerFinding(
                title: $message,
                severity: $severity,
                source: 'nikto',
                category: 'baseline',
                description: $message,
                evidence: [
                    'id' => $id,
                    'url' => $row['url'] ?? $row['uri'] ?? null,
                    'method' => $row['method'] ?? null,
                    'tool' => 'nikto',
                ],
                fingerprint: 'nikto-'.$asset->id.'-'.$id.'-'.sha1($message),
            );
        }

        return array_slice($findings, 0, 80);
    }
}
