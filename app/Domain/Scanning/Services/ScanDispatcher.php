<?php

namespace App\Domain\Scanning\Services;

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

class ScanDispatcher
{
    public function __construct(
        private readonly TargetGuard $guard,
        private readonly ScannerRegistry $registry,
        private readonly DnsOwnershipVerifier $ownershipVerifier,
    ) {}

    public function createScan(Asset $asset, ScanProfile $profile, ?User $user = null): Scan
    {
        $this->guard->assertAllowed($asset);
        $this->ownershipVerifier->assertVerified($asset);

        $limiter = TargetRateLimiter::fromDefaultPolicy();

        if ($profile === ScanProfile::Deep) {
            $cooldownHours = $limiter->policy()->deep_cooldown_hours;
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

        $scan = DB::transaction(function () use ($asset, $profile, $user, $taskTypes, $limiter) {
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
            ]);

            return $scan->fresh('tasks');
        });

        $this->dispatchScanTasks($scan);

        return $scan->fresh('tasks');
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
     * @param  list<\App\Domain\Scanning\DTO\ScannerFinding>  $findings
     */
    public function persistFindings(ScanTask $task, array $findings): void
    {
        $asset = $task->scan->asset;

        foreach ($findings as $finding) {
            Finding::query()->updateOrCreate(
                [
                    'asset_id' => $asset->id,
                    'fingerprint' => $finding->resolvedFingerprint($asset->id),
                ],
                [
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
