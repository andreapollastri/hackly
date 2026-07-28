<?php

namespace App\Domain\Scanning\Scanners;

use App\Domain\Scanning\DTO\BinaryResult;
use App\Domain\Scanning\DTO\ScannerFinding;
use App\Domain\Scanning\Services\BinaryRunner;
use App\Domain\Scanning\Services\TargetGuard;
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
        return (int) config('hackly.nmap.timeout', 840);
    }

    public function estimateCost(): int
    {
        return 2;
    }

    public function buildCommand(Asset $asset, ScanTask $task, string $outputPath): array
    {
        $this->ensureOutputDir($outputPath);

        $nmap = $this->binary('nmap');
        $runner = app(BinaryRunner::class);

        if (! $runner->binaryExists($nmap)) {
            throw new \RuntimeException('nmap binary is not available. Install nmap to enable port scanning.');
        }

        $xmlPath = $outputPath.'.xml';
        $topPorts = (int) config('hackly.nmap.top_ports', 50);
        $timing = (string) config('hackly.nmap.timing', 'T3');
        $delayMs = (int) config('hackly.rate_limits.nmap_delay_ms', 100);
        // Finish before Process::timeout kills the job; leave headroom for XML flush.
        $hostTimeout = max(60, $this->timeoutSeconds() - 60);

        $targets = app(TargetGuard::class)->resolvePublicFacingIps($asset->value);
        if ($targets === []) {
            $targets = [$asset->value];
        }

        return [
            $nmap,
            '-sV',
            '--version-light',
            '-'.$timing,
            '--top-ports',
            (string) $topPorts,
            '--scan-delay',
            $delayMs.'ms',
            '--max-retries',
            '1',
            // Filtered hosts otherwise sit on long RTT probes under soft timing.
            '--initial-rtt-timeout',
            '300ms',
            '--max-rtt-timeout',
            '800ms',
            '--max-scan-delay',
            '1s',
            '--host-timeout',
            $hostTimeout.'s',
            '-oX',
            $xmlPath,
            '-oN',
            $outputPath.'.txt',
            ...$targets,
        ];
    }

    public function parse(Asset $asset, ScanTask $task, BinaryResult $result): array
    {
        $xmlPath = ($result->outputPath ?? '').'.xml';
        $findings = [];

        if (! is_file($xmlPath)) {
            if (trim($result->stdout) !== '' || trim($result->stderr) !== '') {
                $findings[] = new ScannerFinding(
                    title: 'Port scan completed with limited output',
                    severity: FindingSeverity::Low,
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

        $scannedCount = (int) ($xml->scaninfo['numservices'] ?? config('hackly.nmap.top_ports', 50));
        $openPorts = [];

        foreach ($xml->host as $host) {
            $hostAddr = (string) ($host->address['addr'] ?? $asset->value);

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
                $method = (string) ($port->service['method'] ?? '');
                $conf = (int) ($port->service['conf'] ?? 0);

                $openPorts[] = [
                    'host' => $hostAddr,
                    'port' => (int) $portId,
                    'protocol' => $protocol,
                    'service' => $service,
                    'product' => $product,
                    'version' => $version,
                    'method' => $method,
                    'conf' => $conf,
                ];
            }
        }

        $openCount = count($openPorts);
        $suspiciousRatio = $scannedCount > 0 && ($openCount / $scannedCount) >= 0.4;
        $suspiciousAbsolute = $openCount >= 15;
        $likelyMiddlebox = $suspiciousRatio || $suspiciousAbsolute;

        if ($likelyMiddlebox) {
            $openPorts = $this->filterMiddleboxFalsePositives($openPorts);

            $findings[] = new ScannerFinding(
                title: 'Port scan likely distorted by VPN/middlebox',
                severity: FindingSeverity::Medium,
                source: 'nmap',
                category: 'ports',
                description: "Nmap reported {$openCount}/{$scannedCount} ports as open. That pattern usually means a local VPN, endpoint firewall, or transparent proxy is accepting outbound TCP (false positives), not that the target exposes all those services. Re-run with the VPN disconnected for reliable results.",
                evidence: [
                    'open_before_filter' => $openCount,
                    'scanned' => $scannedCount,
                    'kept_after_filter' => count($openPorts),
                    'hint' => 'Disconnect VPN (e.g. F-Secure/WireGuard) and rescan',
                ],
                fingerprint: 'nmap-middlebox-'.$asset->id.'-'.$task->id,
            );
        }

        foreach ($openPorts as $openPort) {
            $hostAddr = $openPort['host'];
            $portId = (string) $openPort['port'];
            $protocol = $openPort['protocol'];
            $service = $openPort['service'];
            $product = $openPort['product'];
            $version = $openPort['version'];

            $title = "Open port {$portId}/{$protocol} on {$hostAddr} ({$service})";
            $severity = in_array((int) $portId, [21, 23, 445, 3389, 5900], true)
                ? FindingSeverity::Medium
                : FindingSeverity::Low;

            $findings[] = new ScannerFinding(
                title: $title,
                severity: $severity,
                source: 'nmap',
                category: 'open_port',
                description: trim("Service fingerprint: {$product} {$version}"),
                evidence: [
                    'host' => $hostAddr,
                    'port' => (int) $portId,
                    'protocol' => $protocol,
                    'service' => $service,
                    'product' => $product,
                    'version' => $version,
                    'method' => $openPort['method'],
                    'conf' => $openPort['conf'],
                ],
                fingerprint: "nmap-port-{$asset->id}-{$hostAddr}-{$protocol}-{$portId}",
            );
        }

        return $findings;
    }

    /**
     * When nearly every port looks open, keep only ports with a real service probe
     * and drop identical catch-all fingerprints repeated across many ports
     * (typical of VPN / transparent proxy intercept).
     *
     * @param  list<array{host: string, port: int, protocol: string, service: string, product: string, version: string, method: string, conf: int}>  $openPorts
     * @return list<array{host: string, port: int, protocol: string, service: string, product: string, version: string, method: string, conf: int}>
     */
    private function filterMiddleboxFalsePositives(array $openPorts): array
    {
        $probed = array_values(array_filter(
            $openPorts,
            fn (array $p): bool => $p['method'] === 'probed' && $p['conf'] >= 8 && $p['product'] !== ''
        ));

        $productCounts = [];
        foreach ($probed as $port) {
            $key = strtolower(trim($port['product']));
            $productCounts[$key] = ($productCounts[$key] ?? 0) + 1;
        }

        return array_values(array_filter(
            $probed,
            function (array $p) use ($productCounts): bool {
                $key = strtolower(trim($p['product']));

                // Same banner on many ports ⇒ almost certainly the middlebox answering.
                return ($productCounts[$key] ?? 0) <= 2;
            }
        ));
    }
}
