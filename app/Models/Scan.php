<?php

namespace App\Models;

use App\Enums\FindingSeverity;
use App\Enums\ScanProfile;
use App\Enums\ScanStatus;
use App\Enums\ScanTaskStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scan extends Model
{
    use HasUuids;

    protected $fillable = [
        'asset_id',
        'profile',
        'status',
        'requested_by',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'profile' => ScanProfile::class,
            'status' => ScanStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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

    public function finishedTasksCount(): int
    {
        $done = [
            ScanTaskStatus::Completed,
            ScanTaskStatus::Failed,
            ScanTaskStatus::Skipped,
        ];

        if ($this->relationLoaded('tasks')) {
            return $this->tasks
                ->filter(fn (ScanTask $task) => in_array($task->status, $done, true))
                ->count();
        }

        return $this->tasks()->whereIn('status', array_map(fn ($s) => $s->value, $done))->count();
    }

    public function totalTasksCount(): int
    {
        if ($this->relationLoaded('tasks')) {
            return $this->tasks->count();
        }

        return $this->tasks()->count();
    }

    public function progressPercent(): int
    {
        $total = $this->totalTasksCount();

        if ($total === 0) {
            return 0;
        }

        return (int) round(($this->finishedTasksCount() / $total) * 100);
    }

    /**
     * @return array{high: int, medium: int, low: int}
     */
    public function findingsSeveritySummary(): array
    {
        if (
            isset($this->high_findings_count, $this->medium_findings_count, $this->low_findings_count)
        ) {
            return [
                'high' => (int) $this->high_findings_count,
                'medium' => (int) $this->medium_findings_count,
                'low' => (int) $this->low_findings_count,
            ];
        }

        $isIssue = fn ($finding): bool => ! in_array($finding->category, ['passed', 'scan_diff'], true);

        if ($this->relationLoaded('findings')) {
            $issues = $this->findings->filter($isIssue);

            return [
                'high' => $issues->where('severity', FindingSeverity::High)->count(),
                'medium' => $issues->where('severity', FindingSeverity::Medium)->count(),
                'low' => $issues->where('severity', FindingSeverity::Low)->count(),
            ];
        }

        return [
            'high' => $this->findings()->where('severity', FindingSeverity::High)->whereNotIn('category', ['passed', 'scan_diff'])->count(),
            'medium' => $this->findings()->where('severity', FindingSeverity::Medium)->whereNotIn('category', ['passed', 'scan_diff'])->count(),
            'low' => $this->findings()->where('severity', FindingSeverity::Low)->whereNotIn('category', ['passed', 'scan_diff'])->count(),
        ];
    }

    public function refreshStatusFromTasks(): void
    {
        $tasks = $this->tasks()->get();

        if ($tasks->isEmpty()) {
            return;
        }

        if ($tasks->every(fn (ScanTask $task) => in_array($task->status->value, ['completed', 'failed', 'skipped'], true))) {
            $allFailed = $tasks->every(fn (ScanTask $task) => $task->status->value === 'failed');
            $nextStatus = $allFailed ? ScanStatus::Failed : ScanStatus::Completed;
            $wasFinished = in_array($this->status, [ScanStatus::Completed, ScanStatus::Failed], true);

            $this->update([
                'status' => $nextStatus,
                'finished_at' => now(),
            ]);

            if (! $wasFinished && $nextStatus === ScanStatus::Completed) {
                try {
                    app(\App\Domain\Scanning\Services\ScanDispatcher::class)->reconcileFindingsAfterScan($this->fresh('tasks') ?? $this);
                } catch (\Throwable) {
                    // Reconciliation must not break scan completion.
                }
            }

            return;
        }

        if ($tasks->contains(fn (ScanTask $task) => in_array($task->status->value, ['running', 'queued'], true))) {
            if ($this->status !== ScanStatus::Running) {
                $this->update([
                    'status' => ScanStatus::Running,
                    'started_at' => $this->started_at ?? now(),
                ]);
            }
        }
    }
}
