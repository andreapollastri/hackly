<?php

namespace App\Models;

use App\Domain\RepoScanning\Services\RepoScanDispatcher;
use App\Enums\FindingSeverity;
use App\Enums\ScanProfile;
use App\Enums\ScanStatus;
use App\Enums\ScanTaskStatus;
use App\Models\Concerns\HasScanLifecycle;
use App\Support\ScanNotifier;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RepoScan extends Model
{
    use HasScanLifecycle;
    use HasUuids;

    protected $fillable = [
        'repository_id',
        'profile',
        'status',
        'commit_sha',
        'workspace_path',
        'requested_by',
        'started_at',
        'finished_at',
        'error_message',
        'findings_summary',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'profile' => ScanProfile::class,
            'status' => ScanStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'findings_summary' => 'array',
            'meta' => 'array',
        ];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(RepoScanTask::class)->orderBy('sort_order');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class, 'repo_scan_id')
            ->orderByRaw(FindingSeverity::orderByRankSql().' desc');
    }

    public function refreshStatusFromTasks(): void
    {
        if ($this->isCancelled()) {
            return;
        }

        $tasks = $this->tasks()->get();

        if ($tasks->isEmpty()) {
            return;
        }

        if ($tasks->every(fn (RepoScanTask $task) => $task->status->isFinished())) {
            $allFailed = $tasks->every(fn (RepoScanTask $task) => $task->status === ScanTaskStatus::Failed);
            $nextStatus = $allFailed ? ScanStatus::Failed : ScanStatus::Completed;

            // Conditional update: only the worker that flips the status runs the follow-ups.
            $transitioned = static::query()
                ->whereKey($this->getKey())
                ->whereNotIn('status', [ScanStatus::Completed->value, ScanStatus::Failed->value, ScanStatus::Cancelled->value])
                ->update([
                    'status' => $nextStatus->value,
                    'finished_at' => now(),
                ]) > 0;

            $this->refresh();

            if ($nextStatus === ScanStatus::Completed) {
                try {
                    app(RepoScanDispatcher::class)
                        ->finalizeScan($this->fresh(['tasks', 'repository.assets']) ?? $this);
                } catch (\Throwable) {
                    // Finalization must not break scan completion.
                }
            }

            if ($transitioned) {
                $this->snapshotFindingsSummary();

                ScanNotifier::finished($this);
            }

            return;
        }

        if ($tasks->contains(fn (RepoScanTask $task) => in_array($task->status, [ScanTaskStatus::Running, ScanTaskStatus::Queued], true))) {
            if ($this->status !== ScanStatus::Running) {
                $this->update([
                    'status' => ScanStatus::Running,
                    'started_at' => $this->started_at ?? now(),
                ]);
            }
        }
    }

    public function subjectName(): string
    {
        return $this->repository?->full_name ?? 'Deleted repository';
    }
}
