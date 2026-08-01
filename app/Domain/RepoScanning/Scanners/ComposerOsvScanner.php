<?php

namespace App\Domain\RepoScanning\Scanners;

use App\Domain\RepoScanning\DTO\RawFinding;
use App\Domain\Scanning\DTO\BinaryResult;
use App\Enums\FindingSeverity;
use App\Enums\RepoScanTaskType;
use App\Models\RepoScan;
use App\Models\RepoScanTask;
use App\Models\Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

class ComposerOsvScanner extends AbstractRepoScanner
{
    public function type(): RepoScanTaskType
    {
        return RepoScanTaskType::ComposerOsv;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('hackly.repo.composer.timeout', 300);
    }

    public function supports(Repository $repository, RepoScan $scan): bool
    {
        $workspace = $scan->workspace_path;

        return is_string($workspace) && is_file($workspace.'/composer.lock');
    }

    public function buildCommand(Repository $repository, RepoScan $scan, RepoScanTask $task, string $outputPath): array
    {
        // In-process scanner.
        return ['true'];
    }

    public function runInProcess(Repository $repository, RepoScan $scan, RepoScanTask $task): ?array
    {
        $workspace = $this->workspace($scan);
        $findings = [];

        $findings = array_merge($findings, $this->fromComposerAudit($workspace));
        $findings = array_merge($findings, $this->fromOsv($workspace));

        return $findings;
    }

    public function parse(Repository $repository, RepoScan $scan, RepoScanTask $task, BinaryResult $result): array
    {
        return [];
    }

    /**
     * @return list<RawFinding>
     */
    private function fromComposerAudit(string $workspace): array
    {
        $composer = (string) config('hackly.binaries.composer', 'composer');
        $result = Process::path($workspace)
            ->timeout($this->timeoutSeconds())
            ->run([$composer, 'audit', '--format=json', '--no-interaction']);

        $json = json_decode($result->output() ?: $result->errorOutput(), true);

        if (! is_array($json)) {
            return [];
        }

        $advisories = $json['advisories'] ?? $json;
        $findings = [];

        if (! is_array($advisories)) {
            return [];
        }

        foreach ($advisories as $package => $items) {
            if (! is_array($items)) {
                continue;
            }

            // composer audit may nest as package => [advisories...] or list with advisory keys
            if (isset($items['advisoryId']) || isset($items['cve'])) {
                $items = [$items];
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $pkg = (string) ($item['packageName'] ?? $package);
                $cve = $this->firstCve($item);
                $title = (string) ($item['title'] ?? ($cve ?: 'Composer advisory'));
                $severity = FindingSeverity::normalize((string) ($item['severity'] ?? 'medium'));

                $findings[] = new RawFinding(
                    title: $title.($pkg !== '' ? " ({$pkg})" : ''),
                    severity: $severity,
                    source: 'composer-audit',
                    category: 'sca',
                    cve: $cve,
                    description: (string) ($item['description'] ?? $title),
                    evidence: [
                        'package' => $pkg,
                        'link' => $item['link'] ?? null,
                        'advisory_id' => $item['advisoryId'] ?? null,
                        'tool' => 'composer-audit',
                    ],
                    package: $pkg !== '' ? $pkg : null,
                    packageVersion: isset($item['affectedVersions']) ? (string) $item['affectedVersions'] : null,
                    file: 'composer.lock',
                    tools: ['composer-audit'],
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<RawFinding>
     */
    private function fromOsv(string $workspace): array
    {
        if (! (bool) config('hackly.repo.composer.osv_enabled', true)) {
            return [];
        }

        $lock = json_decode((string) file_get_contents($workspace.'/composer.lock'), true);

        if (! is_array($lock)) {
            return [];
        }

        $queries = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($lock[$section] ?? [] as $package) {
                if (! is_array($package) || empty($package['name']) || empty($package['version'])) {
                    continue;
                }

                $queries[] = [
                    'package' => [
                        'name' => (string) $package['name'],
                        'ecosystem' => 'Packagist',
                    ],
                    'version' => ltrim((string) $package['version'], 'v'),
                ];
            }
        }

        if ($queries === []) {
            return [];
        }

        $findings = [];

        foreach (array_chunk($queries, 100) as $chunk) {
            $response = Http::timeout(60)
                ->acceptJson()
                ->post('https://api.osv.dev/v1/querybatch', ['queries' => $chunk]);

            if (! $response->successful()) {
                continue;
            }

            $results = $response->json('results') ?? [];

            foreach ($results as $index => $result) {
                if (! is_array($result) || empty($result['vulns']) || ! is_array($result['vulns'])) {
                    continue;
                }

                $query = $chunk[$index] ?? null;
                $pkg = (string) ($query['package']['name'] ?? '');
                $version = (string) ($query['version'] ?? '');

                foreach ($result['vulns'] as $vuln) {
                    if (! is_array($vuln)) {
                        continue;
                    }

                    $id = (string) ($vuln['id'] ?? '');
                    $cve = null;

                    foreach ($vuln['aliases'] ?? [] as $alias) {
                        if (is_string($alias) && str_starts_with($alias, 'CVE-')) {
                            $cve = $alias;
                            break;
                        }
                    }

                    $findings[] = new RawFinding(
                        title: ((string) ($vuln['summary'] ?? $id)).($pkg !== '' ? " ({$pkg})" : ''),
                        severity: $this->osvSeverity($vuln),
                        source: 'osv',
                        category: 'sca',
                        cve: $cve ?? (str_starts_with($id, 'CVE-') ? $id : null),
                        description: (string) ($vuln['details'] ?? $vuln['summary'] ?? $id),
                        evidence: [
                            'package' => $pkg,
                            'version' => $version,
                            'osv_id' => $id,
                            'tool' => 'osv',
                        ],
                        package: $pkg !== '' ? $pkg : null,
                        packageVersion: $version !== '' ? $version : null,
                        file: 'composer.lock',
                        ruleId: $id !== '' ? $id : null,
                        tools: ['osv'],
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function firstCve(array $item): ?string
    {
        foreach (['cve', 'CVE'] as $key) {
            if (! empty($item[$key]) && is_string($item[$key])) {
                return $item[$key];
            }
        }

        foreach ($item['cve'] ?? [] as $cve) {
            if (is_string($cve) && $cve !== '') {
                return $cve;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $vuln
     */
    private function osvSeverity(array $vuln): FindingSeverity
    {
        foreach ($vuln['severity'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (isset($row['score']) && is_numeric($row['score'])) {
                $score = (float) $row['score'];

                return match (true) {
                    $score >= 7.0 => FindingSeverity::High,
                    $score >= 4.0 => FindingSeverity::Medium,
                    default => FindingSeverity::Low,
                };
            }

            if (! empty($row['type']) && strtoupper((string) $row['type']) === 'CVSS_V3' && ! empty($row['score'])) {
                // handled above
            }
        }

        return FindingSeverity::Medium;
    }
}
