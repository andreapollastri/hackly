<?php

namespace App\Domain\Compliance;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\ScanStatus;
use App\Enums\ScanTaskStatus;
use App\Models\Asset;
use App\Models\Finding;
use App\Models\RepoScan;
use App\Models\RepoScanTask;
use App\Models\Repository;
use App\Models\Scan;
use App\Models\ScanTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ComplianceReportBuilder
{
    public const DISCLAIMER = 'This pack is evidence from Hackly scans of targets and repositories you registered. It is not a certification, attestation, or legal opinion. Controls Hackly cannot observe are marked out of scope instead of passed.';

    /**
     * @return array<string, mixed>
     */
    public function build(ComplianceFramework $framework, ?array $evidence = null, ?ComplianceSubject $subject = null): array
    {
        $evidence ??= $this->evidence($subject);
        $controls = array_map(
            fn (array $control): array => $this->assess($control, $evidence),
            ComplianceCatalog::controls($framework),
        );

        $summary = $this->summarize($controls);

        return [
            'id' => $framework->value,
            'name' => $framework->label(),
            'citation' => $framework->citation(),
            'summary' => $framework->summary(),
            'disclaimer' => self::DISCLAIMER,
            'generated_at' => now()->toIso8601String(),
            'score' => $summary['score'],
            'grade' => $summary['grade'],
            'counts' => $summary['counts'],
            'scope' => $evidence['scope'],
            'controls' => $controls,
        ];
    }

    /**
     * Scores for every framework, without the per-control finding lists.
     *
     * @return list<array<string, mixed>>
     */
    public function summaries(?array $evidence = null, ?ComplianceSubject $subject = null): array
    {
        $evidence ??= $this->evidence($subject);

        return array_map(function (ComplianceFramework $framework) use ($evidence): array {
            $report = $this->build($framework, $evidence);

            return [
                'id' => $report['id'],
                'name' => $report['name'],
                'citation' => $report['citation'],
                'summary' => $report['summary'],
                'score' => $report['score'],
                'grade' => $report['grade'],
                'counts' => $report['counts'],
            ];
        }, ComplianceFramework::cases());
    }

    /**
     * @param  list<array<string, mixed>>  $controls
     * @return array{score: ?int, grade: ?string, counts: array<string, int>}
     */
    public function summarize(array $controls): array
    {
        $counts = [
            'met' => 0,
            'partial' => 0,
            'gap' => 0,
            'not_assessed' => 0,
            'out_of_scope' => 0,
        ];

        foreach ($controls as $control) {
            $status = $control['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        $weight = $counts['met'] + $counts['partial'] + $counts['gap'];
        $points = $counts['met'] + ($counts['partial'] * 0.5);
        $score = $weight === 0 ? null : (int) round($points / $weight * 100);

        return [
            'score' => $score,
            'grade' => self::grade($score),
            'counts' => $counts,
        ];
    }

    public static function grade(?int $score): ?string
    {
        if ($score === null) {
            return null;
        }

        return match (true) {
            $score >= 90 => 'A',
            $score >= 75 => 'B',
            $score >= 60 => 'C',
            $score >= 40 => 'D',
            default => 'F',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function evidence(?ComplianceSubject $subject = null): array
    {
        $subject ??= ComplianceSubject::all();

        $findings = $subject->constrainFindings(Finding::query())
            ->issues()
            ->with(['asset:id,value', 'repository:id,full_name'])
            ->get([
                'id', 'asset_id', 'repository_id', 'severity', 'title', 'category',
                'source', 'status', 'cve',
            ]);

        $completedTasks = $subject->constrainScanTasks(
            ScanTask::query()->where('status', ScanTaskStatus::Completed)
        )
            ->distinct()
            ->pluck('type')
            ->map(fn ($type) => $type instanceof \BackedEnum ? $type->value : (string) $type)
            ->merge(
                $subject->constrainRepoScanTasks(
                    RepoScanTask::query()->where('status', ScanTaskStatus::Completed)
                )
                    ->distinct()
                    ->pluck('type')
                    ->map(fn ($type) => $type instanceof \BackedEnum ? $type->value : (string) $type)
            )
            ->unique()
            ->values()
            ->all();

        $lastTarget = $subject->constrainScans(Scan::query()->where('status', ScanStatus::Completed))->max('finished_at');
        $lastRepo = $subject->constrainRepoScans(RepoScan::query()->where('status', ScanStatus::Completed))->max('finished_at');
        $last = collect([$lastTarget, $lastRepo])->filter()->map(fn ($at) => Carbon::parse($at))->sort()->last();

        $targets = $subject->constrainAssets(Asset::query())->count();
        $verified = $subject->constrainAssets(Asset::query())->whereNotNull('verified_at')->count();
        $repositories = $subject->constrainRepositories(Repository::query())->count();

        return [
            'findings' => $findings,
            'completed_tasks' => $completedTasks,
            'last_completed_at' => $last,
            'targets' => $targets,
            'verified_targets' => $verified,
            'repositories' => $repositories,
            'has_scans' => $subject->constrainScans(Scan::query())->exists() || $subject->constrainRepoScans(RepoScan::query())->exists(),
            'scope' => [
                'subject' => $subject->payload(),
                'targets' => $targets,
                'verified_targets' => $verified,
                'repositories' => $repositories,
                'open_high' => $findings->where('status', FindingStatus::Open)->where('severity', FindingSeverity::High)->count(),
                'open_medium' => $findings->where('status', FindingStatus::Open)->where('severity', FindingSeverity::Medium)->count(),
                'open_low' => $findings->where('status', FindingStatus::Open)->where('severity', FindingSeverity::Low)->count(),
                'last_completed_scan_at' => $last?->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $control
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function assess(array $control, array $evidence): array
    {
        $result = match ($control['kind']) {
            'inventory' => $this->assessInventory($evidence),
            'testing' => $this->assessTesting($evidence),
            'triage' => $this->assessTriage($evidence),
            'out_of_scope' => ['status' => 'out_of_scope', 'findings' => collect()],
            default => $this->assessFindings($control, $evidence),
        };

        /** @var Collection<int, Finding> $findings */
        $findings = $result['findings'];
        $shown = $findings->take(8)->map(fn (Finding $finding): array => [
            'id' => $finding->id,
            'title' => $finding->title,
            'severity' => $finding->severity instanceof FindingSeverity ? $finding->severity->value : (string) $finding->severity,
            'status' => $finding->status instanceof FindingStatus ? $finding->status->value : (string) $finding->status,
            'category' => $finding->category,
            'subject' => $finding->subjectName(),
            'cve' => $finding->cve,
        ])->values()->all();

        return [
            'id' => $control['id'],
            'reference' => $control['reference'],
            'title' => $control['title'],
            'requirement' => $control['requirement'],
            'kind' => $control['kind'],
            'status' => $result['status'],
            'note' => $control['note'],
            'open_findings' => $findings->count(),
            'findings' => $shown,
            'more_findings' => max(0, $findings->count() - count($shown)),
        ];
    }

    /**
     * @param  array<string, mixed>  $control
     * @param  array<string, mixed>  $evidence
     * @return array{status: string, findings: Collection<int, Finding>}
     */
    private function assessFindings(array $control, array $evidence): array
    {
        /** @var Collection<int, Finding> $matched */
        $matched = $evidence['findings']->filter(fn (Finding $finding): bool => $this->matches($finding, $control));
        $unresolved = $matched->filter(fn (Finding $finding): bool => in_array($finding->status, [FindingStatus::Open, FindingStatus::Ack], true))->values();
        $highMedium = $unresolved->filter(fn (Finding $finding): bool => in_array($finding->severity, [FindingSeverity::High, FindingSeverity::Medium], true));

        $tasks = $control['tasks'] ?? [];
        $taskHit = $tasks !== [] && array_intersect($tasks, $evidence['completed_tasks']) !== [];
        $assessed = $matched->isNotEmpty() || $taskHit;

        $status = match (true) {
            ! $assessed => 'not_assessed',
            $highMedium->isNotEmpty() => 'gap',
            $unresolved->isNotEmpty() => 'partial',
            default => 'met',
        };

        return ['status' => $status, 'findings' => $unresolved];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array{status: string, findings: Collection<int, Finding>}
     */
    private function assessInventory(array $evidence): array
    {
        $status = match (true) {
            $evidence['targets'] + $evidence['repositories'] === 0 => 'not_assessed',
            $evidence['verified_targets'] > 0 && $evidence['repositories'] > 0 => 'met',
            $evidence['verified_targets'] > 0 || $evidence['repositories'] > 0 => 'partial',
            default => 'gap',
        };

        return ['status' => $status, 'findings' => collect()];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array{status: string, findings: Collection<int, Finding>}
     */
    private function assessTesting(array $evidence): array
    {
        /** @var Carbon|null $last */
        $last = $evidence['last_completed_at'];

        $status = match (true) {
            $last === null && $evidence['targets'] + $evidence['repositories'] === 0 => 'not_assessed',
            $last === null => 'gap',
            $last->gte(now()->subDays(90)) => 'met',
            default => 'partial',
        };

        return ['status' => $status, 'findings' => collect()];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array{status: string, findings: Collection<int, Finding>}
     */
    private function assessTriage(array $evidence): array
    {
        /** @var Collection<int, Finding> $findings */
        $findings = $evidence['findings'];
        $open = $findings->filter(fn (Finding $finding): bool => $finding->status === FindingStatus::Open
            && in_array($finding->severity, [FindingSeverity::High, FindingSeverity::Medium], true));
        $ack = $findings->filter(fn (Finding $finding): bool => $finding->status === FindingStatus::Ack
            && in_array($finding->severity, [FindingSeverity::High, FindingSeverity::Medium], true));

        $status = match (true) {
            ! $evidence['has_scans'] && $findings->isEmpty() => 'not_assessed',
            $open->isNotEmpty() => 'gap',
            $ack->isNotEmpty() => 'partial',
            default => 'met',
        };

        return ['status' => $status, 'findings' => $open->values()];
    }

    /**
     * @param  array<string, mixed>  $control
     */
    private function matches(Finding $finding, array $control): bool
    {
        $categories = $control['categories'] ?? [];

        return $categories !== [] && in_array((string) $finding->category, $categories, true);
    }
}
