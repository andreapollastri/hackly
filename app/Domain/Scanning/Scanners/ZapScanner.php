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

class ZapScanner extends AbstractScanner
{
    public function type(): ScanTaskType
    {
        return ScanTaskType::ZapBaseline;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('hackly.zap.timeout', 900);
    }

    public function estimateCost(): int
    {
        return 4;
    }

    public function buildCommand(Asset $asset, ScanTask $task, string $outputPath): array
    {
        $this->ensureOutputDir($outputPath);

        $zap = $this->binary('zap');
        $runner = app(BinaryRunner::class);

        if (! $runner->binaryExists($zap)) {
            throw new RuntimeException('OWASP ZAP binary is not available. Install ZAP or skip zap_baseline tasks.');
        }

        $reportJson = $outputPath.'.json';
        $homeDir = $outputPath.'.zap-home';
        $maxDuration = (int) config('hackly.zap.max_duration_minutes', 10);

        if (! is_dir($homeDir) && ! mkdir($homeDir, 0755, true) && ! is_dir($homeDir)) {
            throw new RuntimeException("Unable to create ZAP home directory: {$homeDir}");
        }

        // ZAP baseline / docker-style CLI: zap-baseline.py or zap.sh -cmd
        // Always pass an isolated -dir so concurrent/retry runs do not collide on
        // the default ~/Library/Application Support/ZAP (or ~/.ZAP) home lock.
        if (str_contains($zap, 'baseline') || str_ends_with($zap, '.py')) {
            return [
                $zap,
                '-t',
                $asset->httpBaseUrl(),
                '-J',
                $reportJson,
                '-m',
                (string) $maxDuration,
                '-z',
                "-dir {$homeDir}",
            ];
        }

        return [
            $zap,
            '-dir',
            $homeDir,
            '-cmd',
            '-quickurl',
            $asset->httpBaseUrl(),
            '-quickout',
            $reportJson,
            '-quickprogress',
        ];
    }

    public function parse(Asset $asset, ScanTask $task, BinaryResult $result): array
    {
        $reportJson = ($result->outputPath ?? '').'.json';
        $findings = [];

        if (! is_file($reportJson)) {
            // Some ZAP builds write XML/HTML; capture informational finding.
            if (trim($result->stdout.$result->stderr) !== '') {
                $findings[] = new ScannerFinding(
                    title: 'ZAP baseline finished (see raw output)',
                    severity: FindingSeverity::Low,
                    source: 'zap',
                    category: 'baseline',
                    evidence: [
                        'exit_code' => $result->exitCode,
                        'stderr' => substr($result->stderr, 0, 1500),
                    ],
                    fingerprint: 'zap-raw-'.$asset->id.'-'.$task->id,
                );
            }

            return $findings;
        }

        $payload = json_decode((string) file_get_contents($reportJson), true);

        if (! is_array($payload)) {
            return $findings;
        }

        $sites = $payload['site'] ?? [$payload];

        foreach ($sites as $site) {
            $alerts = $site['alerts'] ?? $site['alerts'] ?? [];

            if (! is_array($alerts) && isset($payload['site'])) {
                continue;
            }

            // zap-baseline JSON format
            if (isset($payload['site']) === false && isset($payload[0])) {
                foreach ($payload as $alert) {
                    if (! is_array($alert)) {
                        continue;
                    }
                    $findings[] = $this->mapAlert($alert, $asset);
                }

                continue;
            }

            foreach (($site['alerts'] ?? []) as $alert) {
                if (! is_array($alert)) {
                    continue;
                }
                $findings[] = $this->mapAlert($alert, $asset);
            }
        }

        // Alternative flat list used by some exporters
        if ($findings === [] && isset($payload['alerts']) && is_array($payload['alerts'])) {
            foreach ($payload['alerts'] as $alert) {
                if (is_array($alert)) {
                    $findings[] = $this->mapAlert($alert, $asset);
                }
            }
        }

        return array_values(array_filter($findings));
    }

    /**
     * @param  array<string, mixed>  $alert
     */
    private function mapAlert(array $alert, Asset $asset): ScannerFinding
    {
        $name = (string) ($alert['name'] ?? $alert['alert'] ?? $alert['title'] ?? 'ZAP alert');
        $risk = (string) ($alert['risk'] ?? $alert['riskdesc'] ?? $alert['riskcode'] ?? 'info');
        $pluginId = (string) ($alert['pluginid'] ?? $alert['pluginId'] ?? $alert['alertRef'] ?? md5($name));

        return new ScannerFinding(
            title: $name,
            severity: $this->mapRisk($risk),
            source: 'zap',
            category: (string) ($alert['cweid'] ?? 'owasp'),
            description: strip_tags((string) ($alert['desc'] ?? $alert['description'] ?? '')),
            evidence: [
                'plugin_id' => $pluginId,
                'url' => $alert['url'] ?? ($alert['instances'][0]['uri'] ?? null),
                'confidence' => $alert['confidence'] ?? null,
                'solution' => isset($alert['solution']) ? strip_tags((string) $alert['solution']) : null,
            ],
            fingerprint: 'zap-'.$asset->id.'-'.$pluginId.'-'.md5((string) ($alert['url'] ?? $name)),
        );
    }

    private function mapRisk(string $risk): FindingSeverity
    {
        return FindingSeverity::normalize($risk);
    }
}
