<?php

namespace App\Models;

use App\Domain\Scanning\Services\ScanDispatcher;
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

class Scan extends Model
{
    use HasScanLifecycle;
    use HasUuids;

    protected $fillable = [
        'asset_id',
        'profile',
        'status',
        'requested_by',
        'started_at',
        'finished_at',
        'error_message',
        'findings_summary',
    ];

    protected function casts(): array
    {
        return [
            'profile' => ScanProfile::class,
            'status' => ScanStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'findings_summary' => 'array',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ScanTask::class)->orderBy('sort_order');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class)
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

        if ($tasks->every(fn (ScanTask $task) => $task->status->isFinished())) {
            $allFailed = $tasks->every(fn (ScanTask $task) => $task->status === ScanTaskStatus::Failed);
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

            if (! $transitioned) {
                return;
            }

            if ($nextStatus === ScanStatus::Completed) {
                try {
                    app(ScanDispatcher::class)->reconcileFindingsAfterScan($this->fresh('tasks') ?? $this);
                } catch (\Throwable) {
                    // Reconciliation must not break scan completion.
                }
            }

            $this->snapshotFindingsSummary();

            ScanNotifier::finished($this);

            return;
        }

        if ($tasks->contains(fn (ScanTask $task) => in_array($task->status, [ScanTaskStatus::Running, ScanTaskStatus::Queued], true))) {
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
        return $this->asset?->value ?? 'Deleted target';
    }
}
