<?php

namespace App\Domain\Scanning\Scanners;

use App\Domain\Scanning\DTO\BinaryResult;
use App\Domain\Scanning\DTO\ScannerFinding;
use App\Enums\FindingSeverity;
use App\Enums\ScanTaskType;
use App\Models\Asset;
use App\Models\ScanTask;

class PortScanner extends AbstractScanner
{
    public function type(): ScanTaskType
    {
        return ScanTaskType::PortScan;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('hackly.nmap.timeout', 300);
    }

    public function estimateCost(): int
    {
        return 2;
    }

    public function buildCommand(Asset $asset, ScanTask $task, string $outputPath): array
    {
        $this->ensureOutputDir($outputPath);

        $nmap = $this->binary('nmap');
        $runner = app(\App\Domain\Scanning\Services\BinaryRunner::class);

        if (! $runner->binaryExists($nmap)) {
            throw new \RuntimeException('nmap binary is not available. Install nmap to enable port scanning.');
        }

        $xmlPath = $outputPath.'.xml';
        $topPorts = (int) config('hackly.nmap.top_ports', 100);
        $timing = (string) config('hackly.nmap.timing', 'T2');
        $delayMs = (int) config('hackly.rate_limits.nmap_delay_ms', 200);

        return [
            $nmap,
            '-sV',
            '-'.$timing,
            '--top-ports',
            (string) $topPorts,
            '--scan-delay',
            $delayMs.'ms',
            '-oX',
            $xmlPath,
            '-oN',
            $outputPath.'.txt',
            $asset->value,
        ];
    }

    public function parse(Asset $asset, ScanTask $task, BinaryResult $result): array
    {
        $xmlPath = ($result->outputPath ?? '').'.xml';
        $findings = [];

        if ($result->outputPath && is_file($result->outputPath.'.txt')) {
            // keep raw path reference via task later
        }

        if (! is_file($xmlPath)) {
            if (trim($result->stdout) !== '' || trim($result->stderr) !== '') {
                $findings[] = new ScannerFinding(
                    title: 'Port scan completed with limited output',
                    severity: FindingSeverity::Info,
                    source: 'nmap',
                    category: 'ports',
                    evidence: [
                        'stdout' => substr($result->stdout, 0, 2000),
                        'stderr' => substr($result->stderr, 0, 1000),
                        'exit_code' => $result->exitCode,
                    ],
                    fingerprint: 'nmap-limited-'.$asset->id.'-'.$task->id,
                );
            }

            return $findings;
        }

        $xml = @simplexml_load_file($xmlPath);

        if ($xml === false) {
            return $findings;
        }

        foreach ($xml->host as $host) {
            foreach ($host->ports->port ?? [] as $port) {
                $state = (string) ($port->state['state'] ?? '');

                if ($state !== 'open') {
                    continue;
                }

                $portId = (string) ($port['portid'] ?? '');
                $protocol = (string) ($port['protocol'] ?? 'tcp');
                $service = (string) ($port->service['name'] ?? 'unknown');
                $product = (string) ($port->service['product'] ?? '');
                $version = (string) ($port->service['version'] ?? '');

                $title = "Open port {$portId}/{$protocol} ({$service})";
                $severity = in_array((int) $portId, [21, 23, 445, 3389, 5900], true)
                    ? FindingSeverity::Medium
                    : FindingSeverity::Info;

                $findings[] = new ScannerFinding(
                    title: $title,
                    severity: $severity,
                    source: 'nmap',
                    category: 'open_port',
                    description: trim("Service fingerprint: {$product} {$version}"),
                    evidence: [
                        'port' => (int) $portId,
                        'protocol' => $protocol,
                        'service' => $service,
                        'product' => $product,
                        'version' => $version,
                    ],
                    fingerprint: "nmap-port-{$asset->id}-{$protocol}-{$portId}",
                );
            }
        }

        return $findings;
    }
}
