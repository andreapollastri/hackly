<?php

namespace App\Domain\Scanning\Services;

use App\Domain\RepoScanning\Services\RepoScanDispatcher;
use App\Domain\Scanning\DTO\ScannerFinding;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\ScanProfile;
use App\Enums\ScanStatus;
use App\Enums\ScanTaskStatus;
use App\Enums\ScanTaskType;
use App\Jobs\RunScanTaskJob;
use App\Models\Asset;
use App\Models\Finding;
use App\Models\Scan;
use App\Models\ScanTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ScanDispatcher
{
    public function __construct(
        private readonly TargetGuard $guard,
        private readonly ScannerRegistry $registry,
        private readonly DnsOwnershipVerifier $ownershipVerifier,
    ) {}

    /**
     * @return array{scan: Scan, linked_repo_scans: list<string>}
     */
    public function createScan(
        Asset $asset,
        ScanProfile $profile,
        ?User $user = null,
        bool $includeLinkedRepos = false,
        ?ScanProfile $linkedRepoProfile = null,
        bool $ignoreCooldown = false,
    ): array {
        $this->guard->assertAllowed($asset);
        $this->ownershipVerifier->assertVerified($asset);

        $limiter = TargetRateLimiter::make();

        if ($profile === ScanProfile::Deep && ! $ignoreCooldown) {
            $cooldownHours = $limiter->deepCooldownHours();
            $recentDeep = Scan::query()
                ->where('asset_id', $asset->id)
                ->where('profile', ScanProfile::Deep)
                ->where('created_at', '>=', now()->subHours($cooldownHours))
                ->whereIn('status', [ScanStatus::Pending, ScanStatus::Running, ScanStatus::Completed])
                ->exists();

            if ($recentDeep) {
                throw new InvalidArgumentException("Deep scan cooldown active for {$cooldownHours}h on this target.");
            }
        }

        $taskTypes = config("hackly.profiles.{$profile->value}", []);

        if ($taskTypes === []) {
            throw new InvalidArgumentException("Unknown scan profile [{$profile->value}].");
        }

        $scan = DB::transaction(function () use ($asset, $profile, $user, $taskTypes, $limiter, $includeLinkedRepos) {
            $scan = Scan::query()->create([
                'asset_id' => $asset->id,
                'profile' => $profile,
                'status' => ScanStatus::Pending,
                'requested_by' => $user?->id,
            ]);

            $scheduledAt = now();
            $order = 0;
            $isFirstPending = true;

            foreach ($taskTypes as $type) {
                $taskType = ScanTaskType::from($type);
                $scanner = $this->registry->get($taskType);

                if (! $scanner->supports($asset)) {
                    ScanTask::query()->create([
                        'scan_id' => $scan->id,
                        'type' => $taskType,
                        'queue' => $scanner->queue(),
                        'status' => ScanTaskStatus::Skipped,
                        'sort_order' => $order++,
                        'scheduled_at' => $scheduledAt,
                        'finished_at' => now(),
                        'error_message' => 'Scanner does not support this asset type.',
                    ]);

                    continue;
                }

                if (! $isFirstPending) {
                    $scheduledAt = $scheduledAt
                        ->copy()
                        ->addSeconds($limiter->taskSpacingSeconds() + $limiter->jitterSeconds());
                }

                $isFirstPending = false;

                ScanTask::query()->create([
                    'scan_id' => $scan->id,
                    'type' => $taskType,
                    'queue' => $scanner->queue(),
                    'status' => ScanTaskStatus::Pending,
                    'sort_order' => $order++,
                    'scheduled_at' => $scheduledAt,
                ]);
            }

            Log::info('hackly.scan.created', [
                'scan_id' => $scan->id,
                'asset' => $asset->value,
                'profile' => $profile->value,
                'user_id' => $user?->id,
                'include_linked_repos' => $includeLinkedRepos,
            ]);

            return $scan->fresh('tasks');
        });

        $this->dispatchScanTasks($scan);

        $linkedRepoScans = [];

        if ($includeLinkedRepos) {
            $linkedRepoScans = $this->dispatchLinkedRepoScans(
                $asset,
                $linkedRepoProfile ?? $profile,
                $user,
            );
        }

        return [
            'scan' => $scan->fresh('tasks'),
            'linked_repo_scans' => $linkedRepoScans,
        ];
    }

    /**
     * @return list<string> Repo scan IDs
     */
    private function dispatchLinkedRepoScans(Asset $asset, ScanProfile $profile, ?User $user): array
    {
        $repoDispatcher = app(RepoScanDispatcher::class);
        $scanIds = [];

        $repos = $asset->repositories()
            ->where('status', 'active')
            ->with('credential')
            ->get();

        foreach ($repos as $repository) {
            try {
                $result = $repoDispatcher->createScan(
                    $repository,
                    $profile,
                    $user,
                    includeLinkedTargets: false,
                );
                $scanIds[] = $result['scan']->id;
            } catch (Throwable $e) {
                Log::warning('hackly.scan.linked_repo_scan.failed', [
                    'asset' => $asset->value,
                    'repository' => $repository->full_name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $scanIds;
    }

    public function dispatchScanTasks(Scan $scan): int
    {
        $dispatched = 0;

        $tasks = $scan->tasks()
            ->where('status', ScanTaskStatus::Pending)
            ->orderBy('sort_order')
            ->get();

        foreach ($tasks as $task) {
            $task->update(['status' => ScanTaskStatus::Queued]);

            $job = RunScanTaskJob::dispatch($task->id)->onQueue($task->queue);

            if ($task->scheduled_at !== null && $task->scheduled_at->isFuture()) {
                $job->delay($task->scheduled_at);
            }

            $dispatched++;
        }

        $scan->refreshStatusFromTasks();

        return $dispatched;
    }

    /**
     * @deprecated Kept for CLI compatibility; scans now dispatch immediately on create.
     */
    public function dispatchDueTasks(int $limit = 20): int
    {
        $tasks = ScanTask::query()
            ->with('scan')
            ->where('status', ScanTaskStatus::Pending)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get();

        $dispatched = 0;

        foreach ($tasks as $task) {
            $task->update(['status' => ScanTaskStatus::Queued]);
            RunScanTaskJob::dispatch($task->id)->onQueue($task->queue);
            $task->scan?->refreshStatusFromTasks();
            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * @param  list<ScannerFinding>  $findings
     */
    public function persistFindings(ScanTask $task, array $findings): void
    {
        $asset = $task->scan->asset;

        foreach ($findings as $finding) {
            Finding::query()->updateOrCreate(
                [
                    'fingerprint' => $finding->resolvedFingerprint($asset->id),
                ],
                [
                    'asset_id' => $asset->id,
                    'scan_id' => $task->scan_id,
                    'scan_task_id' => $task->id,
                    'severity' => $finding->severity,
                    'title' => $finding->title,
                    'category' => $finding->category,
                    'cve' => $finding->cve,
                    'source' => $finding->source,
                    'status' => FindingStatus::Open,
                    'evidence' => $this->sanitizeEvidence($finding->evidence),
                    'description' => $finding->description,
                ]
            );
        }
    }

    /**
     * When a scan finishes, mark findings not re-asserted by completed tasks as Fixed,
     * and emit a delta summary (new vs fixed) for baseline tracking.
     *
     * Reconciliation is keyed by previous scan_task.type (not source) so overlapping
     * sources (http/dns/nuclei) from different modules do not close each other.
     */
    public function reconcileFindingsAfterScan(Scan $scan): void
    {
        $scan->loadMissing(['tasks', 'asset']);

        $completedTypes = $scan->tasks
            ->filter(fn (ScanTask $task) => $task->status === ScanTaskStatus::Completed)
            ->map(fn (ScanTask $task) => $task->type->value)
            ->unique()
            ->values()
            ->all();

        if ($completedTypes === []) {
            return;
        }

        $assetId = $scan->asset_id;

        $fixedCount = Finding::query()
            ->where('asset_id', $assetId)
            ->where('status', FindingStatus::Open)
            ->where('category', '!=', 'passed')
            ->where(function ($query) use ($scan) {
                $query->whereNull('scan_id')
                    ->orWhere('scan_id', '!=', $scan->id);
            })
            ->whereHas('scanTask', fn ($query) => $query->whereIn('type', $completedTypes))
            ->update(['status' => FindingStatus::Fixed]);

        $newCount = Finding::query()
            ->where('asset_id', $assetId)
            ->where('scan_id', $scan->id)
            ->where('status', FindingStatus::Open)
            ->where('category', '!=', 'passed')
            ->where('created_at', '>=', $scan->started_at ?? $scan->created_at)
            ->count();

        $touchedThisScan = Finding::query()
            ->where('asset_id', $assetId)
            ->where('scan_id', $scan->id)
            ->where('status', FindingStatus::Open)
            ->where('category', '!=', 'passed')
            ->count();

        Finding::query()->updateOrCreate(
            [
                'fingerprint' => 'scan-diff-'.$assetId,
            ],
            [
                'asset_id' => $assetId,
                'scan_id' => $scan->id,
                'scan_task_id' => null,
                'severity' => FindingSeverity::Low,
                'title' => 'Scan delta vs previous baseline',
                'category' => 'scan_diff',
                'cve' => null,
                'source' => 'hackly',
                'status' => FindingStatus::Open,
                'evidence' => [
                    'scan_id' => $scan->id,
                    'open_findings_this_scan' => $touchedThisScan,
                    'newly_created_this_scan' => $newCount,
                    'auto_fixed' => $fixedCount,
                    'task_types_reconciled' => $completedTypes,
                ],
                'description' => $fixedCount > 0 || $newCount > 0
                    ? "{$newCount} new finding(s) created · {$fixedCount} previous finding(s) auto-marked fixed · {$touchedThisScan} open issue(s) in this scan (excluding passed checks)."
                    : "No delta: {$touchedThisScan} open issue(s) re-confirmed; nothing auto-fixed.",
            ]
        );
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
