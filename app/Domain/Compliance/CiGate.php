<?php

namespace App\Domain\Compliance;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\ScanStatus;
use App\Models\Finding;
use App\Models\RepoScan;
use App\Models\Scan;
use Illuminate\Support\Collection;

/**
 * CI quality gate. A failing gate means open issues at or above the threshold,
 * or — when requested — that Hackly has never completed a scan.
 */
class CiGate
{
    /**
     * @return array<string, mixed>
     */
    public function evaluate(string $threshold = 'high', bool $includeAcknowledged = false, bool $requireScan = false): array
    {
        $floor = FindingSeverity::tryFrom($threshold) ?? FindingSeverity::High;
        $ranks = collect(FindingSeverity::cases())
            ->filter(fn (FindingSeverity $severity): bool => $severity->rank() >= $floor->rank())
            ->map(fn (FindingSeverity $severity): string => $severity->value)
            ->all();

        $base = Finding::query()->issues()->reorder();

        if ($includeAcknowledged) {
            $base->unresolved();
        } else {
            $base->where('status', FindingStatus::Open);
        }

        $counts = (clone $base)
            ->selectRaw('severity, count(*) as aggregate')
            ->groupBy('severity')
            ->pluck('aggregate', 'severity');

        /** @var Collection<int, Finding> $blocking */
        $blocking = (clone $base)
            ->whereIn('severity', $ranks)
            ->with(['asset:id,value', 'repository:id,full_name'])
            ->orderByRaw(FindingSeverity::orderByRankSql().' desc')
            ->limit(25)
            ->get(['id', 'asset_id', 'repository_id', 'title', 'severity', 'status', 'category']);

        $blockingCount = (clone $base)->whereIn('severity', $ranks)->count();
        $assessed = Scan::query()->where('status', ScanStatus::Completed)->exists()
            || RepoScan::query()->where('status', ScanStatus::Completed)->exists();

        $pass = $blockingCount === 0 && (! $requireScan || $assessed);

        $reason = match (true) {
            $requireScan && ! $assessed => 'No completed scan on record.',
            $blockingCount > 0 => $blockingCount.' open finding'.($blockingCount === 1 ? '' : 's').' at or above '.$floor->value.'.',
            default => 'No open findings at or above '.$floor->value.'.',
        };

        return [
            'pass' => $pass,
            'assessed' => $assessed,
            'threshold' => $floor->value,
            'include_acknowledged' => $includeAcknowledged,
            'require_scan' => $requireScan,
            'reason' => $reason,
            'warning' => $assessed ? null : 'No completed scan yet. A passing gate only means nothing is open.',
            'counts' => [
                'high' => (int) ($counts[FindingSeverity::High->value] ?? 0),
                'medium' => (int) ($counts[FindingSeverity::Medium->value] ?? 0),
                'low' => (int) ($counts[FindingSeverity::Low->value] ?? 0),
            ],
            'blocking' => $blockingCount,
            'findings' => $blocking->map(fn (Finding $finding): array => [
                'id' => $finding->id,
                'title' => $finding->title,
                'severity' => $finding->severity->value,
                'status' => $finding->status->value,
                'category' => $finding->category,
                'subject' => $finding->subjectName(),
            ])->all(),
        ];
    }
}
