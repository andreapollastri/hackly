<?php

namespace App\Domain\Compliance;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\ScanStatus;
use App\Models\Asset;
use App\Models\Finding;
use App\Models\RepoScan;
use App\Models\Repository;
use App\Models\Scan;
use Illuminate\Support\Carbon;

class PostureScore
{
    public const METHOD = 'Starts at 100. Open high −15 (cap 60), acknowledged high −5 (cap 20), open medium −5 (cap 25), open low −1 (cap 10), each unverified target −5 (cap 15), and −10 when assets exist but nothing completed a scan in the last 30 days.';

    public function __construct(private readonly ComplianceReportBuilder $compliance) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(bool $includeCompliance = true, ?ComplianceSubject $subject = null): array
    {
        $subject ??= ComplianceSubject::all();
        $open = $this->counts(FindingStatus::Open, $subject);
        $ack = $this->counts(FindingStatus::Ack, $subject);

        $targets = $subject->constrainAssets(Asset::query())->count();
        $verified = $subject->constrainAssets(Asset::query())->whereNotNull('verified_at')->count();
        $repositories = $subject->constrainRepositories(Repository::query())->count();

        $lastTarget = $subject->constrainScans(Scan::query()->where('status', ScanStatus::Completed))->max('finished_at');
        $lastRepo = $subject->constrainRepoScans(RepoScan::query()->where('status', ScanStatus::Completed))->max('finished_at');
        $last = collect([$lastTarget, $lastRepo])->filter()->map(fn ($at) => Carbon::parse($at))->sort()->last();

        $running = $subject->constrainScans(Scan::query()->whereIn('status', ScanStatus::active()))->count()
            + $subject->constrainRepoScans(RepoScan::query()->whereIn('status', ScanStatus::active()))->count();

        $score = 100;
        $score -= min(60, $open['high'] * 15);
        $score -= min(20, $ack['high'] * 5);
        $score -= min(25, $open['medium'] * 5);
        $score -= min(10, $open['low']);
        $score -= min(15, max(0, $targets - $verified) * 5);

        $stale = ($targets + $repositories) > 0 && ($last === null || $last->lt(now()->subDays(30)));

        if ($stale) {
            $score -= 10;
        }

        $score = max(0, min(100, $score));

        $payload = [
            'score' => $score,
            'grade' => ComplianceReportBuilder::grade($score),
            'method' => self::METHOD,
            'subject' => $subject->payload(),
            'open' => $open,
            'acknowledged' => $ack,
            'assets' => [
                'targets' => $targets,
                'verified_targets' => $verified,
                'repositories' => $repositories,
            ],
            'scans' => [
                'running' => $running,
                'last_30_days' => $subject->constrainScans(Scan::query()->where('created_at', '>=', now()->subDays(30)))->count()
                    + $subject->constrainRepoScans(RepoScan::query()->where('created_at', '>=', now()->subDays(30)))->count(),
                'last_completed_at' => $last?->toIso8601String(),
                'stale' => $stale,
            ],
        ];

        if ($includeCompliance) {
            $payload['compliance'] = $this->compliance->summaries(subject: $subject);
        }

        return $payload;
    }

    /**
     * @return array{high: int, medium: int, low: int}
     */
    private function counts(FindingStatus $status, ComplianceSubject $subject): array
    {
        $counts = $subject->constrainFindings(Finding::query())
            ->issues()
            ->reorder()
            ->where('status', $status)
            ->selectRaw('severity, count(*) as aggregate')
            ->groupBy('severity')
            ->pluck('aggregate', 'severity');

        return [
            'high' => (int) ($counts[FindingSeverity::High->value] ?? 0),
            'medium' => (int) ($counts[FindingSeverity::Medium->value] ?? 0),
            'low' => (int) ($counts[FindingSeverity::Low->value] ?? 0),
        ];
    }
}
