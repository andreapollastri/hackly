<?php

namespace App\Domain\RepoScanning\Services;

use App\Domain\RepoScanning\DTO\RawFinding;
use App\Domain\Scanning\Services\ScanDispatcher;
use App\Enums\AssetStatus;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\Reachability;
use App\Enums\RepoScanTaskType;
use App\Enums\ScanProfile;
use App\Enums\ScanStatus;
use App\Enums\ScanTaskStatus;
use App\Jobs\PrepareRepoScanJob;
use App\Jobs\RunRepoScanTaskJob;
use App\Models\Finding;
use App\Models\RepoScan;
use App\Models\RepoScanTask;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class RepoScanDispatcher
{
    public function __construct(
        private readonly RepoScannerRegistry $registry,
        private readonly FindingDeduplicator $deduplicator,
        private readonly NoiseFilter $noiseFilter,
        private readonly PhpReachabilityAnalyzer $reachability,
        private readonly RepoCloner $cloner,
    ) {}

    /**
     * @param  list<string>  $skipAssetIds  Asset IDs already queued in this batch (avoid duplicates)
     * @return array{scan: RepoScan, linked_target_scans: list<string>}
     */
    public function createScan(
        Repository $repository,
        ScanProfile $profile,
        ?User $user = null,
        bool $includeLinkedTargets = false,
        ?ScanProfile $linkedTargetProfile = null,
        bool $ignoreTargetCooldown = false,
        array $skipAssetIds = [],
    ): array {
        if (! $repository->isActive()) {
            throw new InvalidArgumentException('Repository is not active.');
        }

        if (! $repository->credential) {
            throw new InvalidArgumentException('Repository has no GitHub credential.');
        }

        $taskTypes = config("hackly.repo.profiles.{$profile->value}", []);

        if ($taskTypes === []) {
            throw new InvalidArgumentException("Unknown repo scan profile [{$profile->value}].");
        }

        // Live pentest only makes sense when targets are explicitly included.
        if (! $includeLinkedTargets) {
            $taskTypes = array_values(array_filter(
                $taskTypes,
                fn (string $type) => $type !== RepoScanTaskType::LaravelLivePentest->value
            ));
        }

        $scan = DB::transaction(function () use ($repository, $profile, $user, $taskTypes, $includeLinkedTargets) {
            $scan = RepoScan::query()->create([
                'repository_id' => $repository->id,
                'profile' => $profile,
                'status' => ScanStatus::Pending,
                'requested_by' => $user?->id,
                'meta' => [
                    'include_linked_targets' => $includeLinkedTargets,
                ],
            ]);

            $order = 0;

            foreach ($taskTypes as $type) {
                $taskType = RepoScanTaskType::from($type);
                $scanner = $this->registry->get($taskType);

                RepoScanTask::query()->create([
                    'repo_scan_id' => $scan->id,
                    'type' => $taskType,
                    'queue' => $scanner->queue(),
                    'status' => ScanTaskStatus::Pending,
                    'sort_order' => $order++,
                    'scheduled_at' => now(),
                ]);
            }

            Log::info('hackly.repo.scan.created', [
                'repo_scan_id' => $scan->id,
                'repository' => $repository->full_name,
                'profile' => $profile->value,
                'include_linked_targets' => $includeLinkedTargets,
            ]);

            return $scan->fresh('tasks');
        });

        PrepareRepoScanJob::dispatch($scan->id)->onQueue('default');

        $linkedTargetScans = [];

        if ($includeLinkedTargets) {
            $linkedTargetScans = $this->dispatchLinkedTargetScans(
                $repository,
                $linkedTargetProfile ?? ScanProfile::Deep,
                $user,
                $ignoreTargetCooldown,
                $skipAssetIds,
            );

            $scan->update([
                'meta' => array_merge($scan->meta ?? [], [
                    'include_linked_targets' => true,
                    'linked_target_scan_ids' => $linkedTargetScans,
                ]),
            ]);
        }

        return [
            'scan' => $scan->fresh('tasks'),
            'linked_target_scans' => $linkedTargetScans,
        ];
    }

    /**
     * @param  list<string>  $skipAssetIds
     * @return list<string> Scan IDs
     */
    private function dispatchLinkedTargetScans(
        Repository $repository,
        ScanProfile $profile,
        ?User $user,
        bool $ignoreCooldown,
        array $skipAssetIds,
    ): array {
        $scanDispatcher = app(ScanDispatcher::class);
        $scanIds = [];

        $assets = $repository->assets()
            ->where('status', AssetStatus::Active->value)
            ->whereNotNull('verified_at')
            ->get();

        foreach ($assets as $asset) {
            if (in_array($asset->id, $skipAssetIds, true)) {
                continue;
            }

            try {
                $result = $scanDispatcher->createScan(
                    $asset,
                    $profile,
                    $user,
                    includeLinkedRepos: false,
                    ignoreCooldown: $ignoreCooldown,
                );
                $scanIds[] = $result['scan']->id;
            } catch (Throwable $e) {
                Log::warning('hackly.repo.linked_target_scan.failed', [
                    'repository' => $repository->full_name,
                    'asset' => $asset->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $scanIds;
    }

    public function dispatchTasks(RepoScan $scan): int
    {
        $dispatched = 0;

        $tasks = $scan->tasks()
            ->where('status', ScanTaskStatus::Pending)
            ->orderBy('sort_order')
            ->get();

        foreach ($tasks as $task) {
            $scanner = $this->registry->get($task->type);
            $repository = $scan->repository;

            if ($repository && ! $scanner->supports($repository, $scan)) {
                $task->update([
                    'status' => ScanTaskStatus::Skipped,
                    'finished_at' => now(),
                    'error_message' => 'Scanner not applicable to this repository.',
                ]);

                continue;
            }

            $task->update(['status' => ScanTaskStatus::Queued]);
            RunRepoScanTaskJob::dispatch($task->id)->onQueue($task->queue);
            $dispatched++;
        }

        $scan->refreshStatusFromTasks();

        return $dispatched;
    }

    /**
     * @param  list<RawFinding>  $rawFindings
     */
    public function persistTaskFindings(RepoScanTask $task, array $rawFindings): void
    {
        $scan = $task->scan()->with('repository')->first();
        $repository = $scan?->repository;

        if (! $scan || ! $repository) {
            return;
        }

        foreach ($rawFindings as $finding) {
            $fingerprint = sha1('repo|'.$repository->id.'|'.$finding->resolvedDedupeKey());

            Finding::query()->updateOrCreate(
                [
                    'fingerprint' => $fingerprint,
                ],
                [
                    'asset_id' => null,
                    'repository_id' => $repository->id,
                    'repo_scan_id' => $scan->id,
                    'repo_scan_task_id' => $task->id,
                    'scan_id' => null,
                    'scan_task_id' => null,
                    'severity' => $finding->severity,
                    'title' => $finding->title,
                    'category' => $finding->category,
                    'cve' => $finding->cve,
                    'source' => $finding->source,
                    'status' => FindingStatus::Open,
                    'reachability' => Reachability::Unknown,
                    'noise_filtered' => (bool) ($finding->evidence['noise_filtered'] ?? false),
                    'confidence' => null,
                    'evidence' => $this->sanitizeEvidence(array_merge($finding->evidence, [
                        'package' => $finding->package,
                        'package_version' => $finding->packageVersion,
                        'file' => $finding->file,
                        'line' => $finding->line,
                        'rule_id' => $finding->ruleId,
                        'tools' => $finding->tools !== [] ? $finding->tools : [$finding->source],
                        'dedupe_key' => $finding->resolvedDedupeKey(),
                        'repository' => $repository->full_name,
                        'commit_sha' => $scan->commit_sha,
                    ])),
                    'description' => $finding->description,
                ]
            );
        }
    }

    public function finalizeScan(RepoScan $scan): void
    {
        $lock = Cache::lock('hackly-repo-scan-finalize-'.$scan->id, 180);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->finalizeScanLocked($scan->fresh(['repository.assets', 'tasks']) ?? $scan);
        } finally {
            $lock->release();
        }
    }

    private function finalizeScanLocked(RepoScan $scan): void
    {
        if (($scan->meta['finalized'] ?? false) === true) {
            return;
        }

        $scan->loadMissing(['repository.assets', 'tasks']);
        $repository = $scan->repository;

        if (! $repository || ! is_string($scan->workspace_path) || ! is_dir($scan->workspace_path)) {
            $this->cloner->cleanup($scan);
            $repository?->update(['last_scanned_at' => now()]);
            $scan->update(['meta' => array_merge($scan->meta ?? [], ['finalized' => true])]);

            return;
        }

        $context = $this->reachability->buildContext($scan->workspace_path);

        $openFindings = Finding::query()
            ->where('repo_scan_id', $scan->id)
            ->where('category', '!=', 'passed')
            ->get();

        /** @var list<RawFinding> $raw */
        $raw = [];

        foreach ($openFindings as $stored) {
            $evidence = is_array($stored->evidence) ? $stored->evidence : [];
            $raw[] = new RawFinding(
                title: $stored->title,
                severity: $stored->severity,
                source: $stored->source,
                category: (string) $stored->category,
                cve: $stored->cve,
                description: $stored->description,
                evidence: $evidence,
                package: isset($evidence['package']) ? (string) $evidence['package'] : null,
                packageVersion: isset($evidence['package_version']) ? (string) $evidence['package_version'] : null,
                file: isset($evidence['file']) ? (string) $evidence['file'] : null,
                line: isset($evidence['line']) ? (int) $evidence['line'] : null,
                ruleId: isset($evidence['rule_id']) ? (string) $evidence['rule_id'] : null,
                tools: is_array($evidence['tools'] ?? null) ? $evidence['tools'] : [$stored->source],
                dedupeKey: isset($evidence['dedupe_key']) ? (string) $evidence['dedupe_key'] : null,
            );
        }

        $deduped = $this->deduplicator->dedupe($raw);
        $noise = $this->noiseFilter->filter($deduped, [
            'dev_packages' => $context['dev_packages'],
            'is_laravel' => $context['is_laravel'],
        ]);
        $analyzed = $this->reachability->analyze($scan->workspace_path, $noise['kept'], $context);

        Finding::query()->where('repo_scan_id', $scan->id)->where('category', '!=', 'passed')->delete();

        $reachableCount = 0;
        $unreachableCount = 0;
        $filteredCount = count($noise['filtered']);

        foreach ($analyzed as $row) {
            /** @var RawFinding $finding */
            $finding = $row['finding'];
            $reachability = $row['reachability'];
            $confidence = $row['confidence'];

            if ($reachability === Reachability::Reachable) {
                $reachableCount++;
            } elseif ($reachability === Reachability::Unreachable) {
                $unreachableCount++;
            }

            $hideUnreachable = (bool) config('hackly.repo.reachability.hide_unreachable', false);

            if ($hideUnreachable && $reachability === Reachability::Unreachable) {
                continue;
            }

            $severity = $finding->severity;
            if ($reachability === Reachability::Unreachable && $finding->category === 'sca') {
                $severity = FindingSeverity::Low;
            }

            $fingerprint = sha1('repo|'.$repository->id.'|'.$finding->resolvedDedupeKey().'|final');

            Finding::query()->updateOrCreate(
                [
                    'fingerprint' => $fingerprint,
                ],
                [
                    'asset_id' => null,
                    'repository_id' => $repository->id,
                    'repo_scan_id' => $scan->id,
                    'repo_scan_task_id' => null,
                    'severity' => $severity,
                    'title' => $finding->title,
                    'category' => $finding->category,
                    'cve' => $finding->cve,
                    'source' => count($finding->tools) > 1 ? 'hackly-repo' : $finding->source,
                    'status' => FindingStatus::Open,
                    'reachability' => $reachability,
                    'noise_filtered' => (bool) ($finding->evidence['noise_filtered'] ?? false),
                    'confidence' => $confidence,
                    'evidence' => $this->sanitizeEvidence(array_merge($finding->evidence, [
                        'reachability_reasons' => $row['reasons'],
                        'tools' => $finding->tools,
                        'repository' => $repository->full_name,
                        'commit_sha' => $scan->commit_sha,
                        'is_laravel' => $context['is_laravel'],
                    ])),
                    'description' => $finding->description,
                ]
            );
        }

        Finding::query()->updateOrCreate(
            [
                'fingerprint' => 'repo-scan-summary-'.$scan->id,
            ],
            [
                'asset_id' => null,
                'repository_id' => $repository->id,
                'repo_scan_id' => $scan->id,
                'severity' => FindingSeverity::Low,
                'title' => 'Repo scan context summary',
                'category' => 'scan_diff',
                'source' => 'hackly-repo',
                'status' => FindingStatus::Open,
                'reachability' => Reachability::Unknown,
                'evidence' => [
                    'reachable' => $reachableCount,
                    'unreachable' => $unreachableCount,
                    'noise_annotated' => $filteredCount,
                    'deduped_total' => count($analyzed),
                    'is_laravel' => $context['is_laravel'],
                    'linked_targets' => $repository->assets->pluck('value')->all(),
                    'include_linked_targets' => (bool) ($scan->meta['include_linked_targets'] ?? false),
                ],
                'description' => "Deduped findings: {$reachableCount} reachable, {$unreachableCount} unreachable, {$filteredCount} noise-annotated. Laravel: ".($context['is_laravel'] ? 'yes' : 'no').'.',
            ]
        );

        $repository->update(['last_scanned_at' => now()]);
        $scan->update(['meta' => array_merge($scan->meta ?? [], [
            'finalized' => true,
            'reachable' => $reachableCount,
            'unreachable' => $unreachableCount,
            'noise_annotated' => $filteredCount,
        ])]);

        if ((bool) config('hackly.repo.cleanup_workspace', true)) {
            $this->cloner->cleanup($scan);
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function sanitizeEvidence(array $evidence): array
    {
        $json = json_encode($evidence, JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            return ['note' => 'evidence_unavailable'];
        }

        if (strlen($json) > 20000) {
            return [
                'note' => 'evidence_truncated',
                'preview' => substr($json, 0, 2000),
            ];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true) ?? [];

        return $decoded;
    }
}
