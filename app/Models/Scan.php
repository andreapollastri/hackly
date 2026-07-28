<?php

namespace App\Models;

use App\Enums\ScanProfile;
use App\Enums\ScanStatus;
use App\Enums\ScanTaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scan extends Model
{
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
        return $this->hasMany(ScanTask::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
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

    public function refreshStatusFromTasks(): void
    {
        $tasks = $this->tasks()->get();

        if ($tasks->isEmpty()) {
            return;
        }

        if ($tasks->every(fn (ScanTask $task) => in_array($task->status->value, ['completed', 'failed', 'skipped'], true))) {
            $failed = $tasks->contains(fn (ScanTask $task) => $task->status->value === 'failed');

            $this->update([
                'status' => $failed && $tasks->every(fn (ScanTask $task) => $task->status->value === 'failed')
                    ? ScanStatus::Failed
                    : ScanStatus::Completed,
                'finished_at' => now(),
            ]);

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
