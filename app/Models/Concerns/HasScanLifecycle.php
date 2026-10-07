<?php

namespace App\Models\Concerns;

use App\Enums\FindingSeverity;
use App\Enums\ScanStatus;
use App\Enums\ScanTaskStatus;
use App\Models\Finding;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\DB;

/**
 * Shared progress, duration and cancellation logic for target scans and repository scans.
 *
 * @property ScanStatus $status
 */
trait HasScanLifecycle
{
    public function finishedTasksCount(): int
    {
        if ($this->relationLoaded('tasks')) {
            return $this->tasks
                ->filter(fn ($task) => $task->status->isFinished())
                ->count();
        }

        return $this->tasks()
            ->whereIn('status', array_map(fn (ScanTaskStatus $s) => $s->value, ScanTaskStatus::finished()))
            ->count();
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
     * Issue counts by severity — passed checks and scan deltas are excluded.
     *
     * @return array{high: int, medium: int, low: int}
     */
    public function findingsSeveritySummary(): array
    {
        // Finished scans keep the counts they had when they completed.
        if (is_array($this->findings_summary) && isset($this->findings_summary['high'])) {
            return [
                'high' => (int) $this->findings_summary['high'],
                'medium' => (int) ($this->findings_summary['medium'] ?? 0),
                'low' => (int) ($this->findings_summary['low'] ?? 0),
            ];
        }

        if (isset($this->high_findings_count, $this->medium_findings_count, $this->low_findings_count)) {
            return [
                'high' => (int) $this->high_findings_count,
                'medium' => (int) $this->medium_findings_count,
                'low' => (int) $this->low_findings_count,
            ];
        }

        if ($this->relationLoaded('findings')) {
            $issues = $this->findings->filter(fn (Finding $finding): bool => $finding->isIssue());

            return [
                'high' => $issues->where('severity', FindingSeverity::High)->count(),
                'medium' => $issues->where('severity', FindingSeverity::Medium)->count(),
                'low' => $issues->where('severity', FindingSeverity::Low)->count(),
            ];
        }

        $counts = $this->findings()
            ->reorder()
            ->issues()
            ->selectRaw('severity, count(*) as aggregate')
            ->groupBy('severity')
            ->pluck('aggregate', 'severity');

        return [
            'high' => (int) ($counts[FindingSeverity::High->value] ?? 0),
            'medium' => (int) ($counts[FindingSeverity::Medium->value] ?? 0),
            'low' => (int) ($counts[FindingSeverity::Low->value] ?? 0),
        ];
    }

    /**
     * Freeze the current issue counts on the scan (findings move to newer scans when re-detected).
     */
    public function snapshotFindingsSummary(): void
    {
        $this->forceFill(['findings_summary' => null]);
        $this->unsetRelation('findings');
        unset($this->high_findings_count, $this->medium_findings_count, $this->low_findings_count);

        $this->forceFill(['findings_summary' => $this->findingsSeveritySummary()])->saveQuietly();
    }

    /**
     * Eager-load counters used by tables (avoids N+1 queries on the summary column).
     *
     * @return array<string, \Closure>
     */
    public static function severityCountsForQuery(): array
    {
        return [
            'findings as high_findings_count' => fn ($q) => $q->reorder()->issues()->where('severity', FindingSeverity::High),
            'findings as medium_findings_count' => fn ($q) => $q->reorder()->issues()->where('severity', FindingSeverity::Medium),
            'findings as low_findings_count' => fn ($q) => $q->reorder()->issues()->where('severity', FindingSeverity::Low),
        ];
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function canBeCancelled(): bool
    {
        return $this->isActive();
    }

    /**
     * Stop a scan: tasks that have not started yet are skipped, the scan is marked cancelled.
     * Tasks already running finish their current tool invocation but their jobs exit early afterwards.
     */
    public function cancel(): void
    {
        if (! $this->canBeCancelled()) {
            return;
        }

        DB::transaction(function (): void {
            $this->tasks()
                ->whereIn('status', [ScanTaskStatus::Pending->value, ScanTaskStatus::Queued->value])
                ->update([
                    'status' => ScanTaskStatus::Skipped->value,
                    'finished_at' => now(),
                    'error_message' => 'Cancelled by user.',
                ]);

            $this->update([
                'status' => ScanStatus::Cancelled,
                'finished_at' => now(),
            ]);
        });
    }

    public function isCancelled(): bool
    {
        return $this->status === ScanStatus::Cancelled;
    }

    public function durationInSeconds(): ?int
    {
        $start = $this->started_at ?? $this->created_at;

        if ($start === null) {
            return null;
        }

        $end = $this->finished_at ?? ($this->isActive() ? now() : null);

        if ($end === null) {
            return null;
        }

        return max(0, (int) $start->diffInSeconds($end));
    }

    public function durationForHumans(): ?string
    {
        $seconds = $this->durationInSeconds();

        if ($seconds === null) {
            return null;
        }

        if ($seconds < 60) {
            return $seconds.'s';
        }

        return CarbonInterval::seconds($seconds)->cascade()->forHumans(['short' => true, 'parts' => 2]);
    }
}
