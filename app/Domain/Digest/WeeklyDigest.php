<?php

namespace App\Domain\Digest;

use App\Domain\Compliance\PostureScore;
use App\Enums\AssetStatus;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\ScanStatus;
use App\Models\Asset;
use App\Models\Finding;
use App\Models\RepoScan;
use App\Models\Repository;
use App\Models\Scan;
use Illuminate\Support\Carbon;

/**
 * Sunday snapshot of open findings, what changed in the last seven days,
 * and the operational situations around them (unverified, paused, failed, stale).
 */
class WeeklyDigest
{
    public function __construct(private readonly PostureScore $posture) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?Carbon $until = null): array
    {
        $until = ($until ?? now())->copy();
        $since = $until->copy()->subWeek();

        $posture = $this->posture->snapshot(includeCompliance: false);

        $new = $this->issues()
            ->where('created_at', '>=', $since)
            ->where('created_at', '<', $until)
            ->limit(20)
            ->get();

        $newCount = $this->issues()
            ->where('created_at', '>=', $since)
            ->where('created_at', '<', $until)
            ->count();

        $falsePositives = $this->issues()
            ->where('status', FindingStatus::FalsePositive)
            ->where('false_positive_noted_at', '>=', $since)
            ->where('false_positive_noted_at', '<', $until)
            ->limit(20)
            ->get();

        $fixed = $this->changed($since, $until, FindingStatus::Fixed);
        $acknowledged = $this->changed($since, $until, FindingStatus::Ack);

        $categories = Finding::query()
            ->issues()
            ->reorder()
            ->where('status', FindingStatus::Open)
            ->selectRaw('category, count(*) as aggregate')
            ->groupBy('category')
            ->orderByDesc('aggregate')
            ->get()
            ->map(fn (Finding $row): array => [
                'category' => Finding::categoryLabel($row->category),
                'count' => (int) $row->aggregate,
            ])
            ->all();

        $openList = $this->issues()
            ->where('status', FindingStatus::Open)
            ->limit(25)
            ->get()
            ->map(fn (Finding $finding): array => $this->row($finding))
            ->all();

        $failed = $this->failedScans($since, $until);
        $completed = Scan::query()->where('status', ScanStatus::Completed)->whereBetween('finished_at', [$since, $until])->count()
            + RepoScan::query()->where('status', ScanStatus::Completed)->whereBetween('finished_at', [$since, $until])->count();

        $report = [
            'subject' => 'Hackly weekly digest — '.$until->timezone(config('app.timezone'))->format('j M Y'),
            'period' => [
                'from' => $since->toIso8601String(),
                'until' => $until->toIso8601String(),
                'label' => $since->timezone(config('app.timezone'))->format('j M').' – '.$until->timezone(config('app.timezone'))->format('j M Y'),
            ],
            'posture' => [
                'score' => $posture['score'],
                'grade' => $posture['grade'],
            ],
            'open' => $posture['open'],
            'acknowledged' => $posture['acknowledged'],
            'new_count' => $newCount,
            'new' => $new->map(fn (Finding $finding): array => $this->row($finding))->all(),
            'triage' => [
                'fixed' => $fixed,
                'acknowledged' => $acknowledged,
                'false_positives' => $falsePositives->map(fn (Finding $finding): array => $this->row($finding) + [
                    'note' => $finding->false_positive_note,
                ])->all(),
            ],
            'categories' => $categories,
            'open_findings' => $openList,
            'scans' => [
                'completed' => $completed,
                'failed' => $failed,
            ],
            'situations' => $this->situations(),
            'findings_url' => url('/findings'),
        ];

        $report['text'] = $this->plain($report);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function plain(array $report): string
    {
        $open = $report['open'];
        $ack = $report['acknowledged'];
        $lines = [
            $report['subject'],
            'Period: '.$report['period']['label'],
            '',
            'Posture '.$report['posture']['grade'].' ('.$report['posture']['score'].'/100)',
            "Open now: {$open['high']} high · {$open['medium']} medium · {$open['low']} low",
            "Acknowledged: {$ack['high']} high · {$ack['medium']} medium · {$ack['low']} low",
            'New this week: '.$report['new_count'],
            'Scans completed this week: '.$report['scans']['completed'],
            '',
            'Situations',
        ];

        foreach ($report['situations'] as $situation) {
            $lines[] = '- '.$situation['title'].($situation['detail'] !== '' ? ': '.$situation['detail'] : '');
        }

        if ($report['categories'] !== []) {
            $lines[] = '';
            $lines[] = 'Open issues by category';

            foreach ($report['categories'] as $category) {
                $lines[] = '- '.$category['category'].': '.$category['count'];
            }
        }

        $lines[] = '';
        $lines[] = 'New this week';
        $lines = array_merge($lines, $this->bulletFindings($report['new']));

        $lines[] = '';
        $lines[] = 'Marked false positive this week';
        $lines = array_merge($lines, $this->bulletFindings($report['triage']['false_positives'], withNote: true));

        $lines[] = '';
        $lines[] = 'Marked fixed this week';
        $lines = array_merge($lines, $this->bulletFindings($report['triage']['fixed']));

        $lines[] = '';
        $lines[] = 'Acknowledged this week';
        $lines = array_merge($lines, $this->bulletFindings($report['triage']['acknowledged']));

        if ($report['scans']['failed'] !== []) {
            $lines[] = '';
            $lines[] = 'Failed scans this week';

            foreach ($report['scans']['failed'] as $scan) {
                $lines[] = '- '.$scan['subject'].' ('.$scan['kind'].'): '.$scan['error'];
            }
        }

        $lines[] = '';
        $lines[] = 'Open findings';
        $lines = array_merge($lines, $this->bulletFindings($report['open_findings']));

        $lines[] = '';
        $lines[] = 'Open the list: '.$report['findings_url'];

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<array{title: string, detail: string}>
     */
    private function situations(): array
    {
        $unverified = Asset::query()->whereNull('verified_at')->orderBy('value')->pluck('value');
        $pausedTargets = Asset::query()->where('status', AssetStatus::Paused)->orderBy('value')->pluck('value');
        $pausedRepos = Repository::query()->where('status', 'paused')->orderBy('full_name')->pluck('full_name');

        $staleTargets = Asset::query()
            ->where('status', AssetStatus::Active)
            ->whereNotNull('verified_at')
            ->whereDoesntHave('scans', fn ($query) => $query
                ->where('status', ScanStatus::Completed)
                ->where('finished_at', '>=', now()->subDays(30)))
            ->orderBy('value')
            ->pluck('value');

        $staleRepos = Repository::query()
            ->where('status', 'active')
            ->whereDoesntHave('scans', fn ($query) => $query
                ->where('status', ScanStatus::Completed)
                ->where('finished_at', '>=', now()->subDays(30)))
            ->orderBy('full_name')
            ->pluck('full_name');

        return array_values(array_filter([
            $this->situation('Unverified targets', $unverified->all(), 'None — every target has a DNS TXT check.'),
            $this->situation('Paused targets', $pausedTargets->all(), 'None.'),
            $this->situation('Paused repositories', $pausedRepos->all(), 'None.'),
            $this->situation('Verified targets with no completed scan in 30 days', $staleTargets->all(), 'None.'),
            $this->situation('Repositories with no completed scan in 30 days', $staleRepos->all(), 'None.'),
        ]));
    }

    /**
     * @param  list<string>  $items
     * @return array{title: string, detail: string}
     */
    private function situation(string $title, array $items, string $empty): array
    {
        if ($items === []) {
            return ['title' => $title, 'detail' => $empty];
        }

        $shown = array_slice($items, 0, 12);
        $extra = count($items) - count($shown);
        $detail = implode(', ', $shown);

        if ($extra > 0) {
            $detail .= ' and '.$extra.' more';
        }

        return ['title' => $title, 'detail' => $detail];
    }

    /**
     * @return list<array{subject: string, kind: string, error: string}>
     */
    private function failedScans(Carbon $since, Carbon $until): array
    {
        $targets = Scan::query()
            ->where('status', ScanStatus::Failed)
            ->whereBetween('updated_at', [$since, $until])
            ->with('asset:id,value')
            ->limit(8)
            ->get()
            ->map(fn (Scan $scan): array => [
                'subject' => $scan->asset?->value ?? 'target',
                'kind' => 'target',
                'error' => $scan->error_message ?: 'Scan failed.',
            ]);

        $repos = RepoScan::query()
            ->where('status', ScanStatus::Failed)
            ->whereBetween('updated_at', [$since, $until])
            ->with('repository:id,full_name')
            ->limit(8)
            ->get()
            ->map(fn (RepoScan $scan): array => [
                'subject' => $scan->repository?->full_name ?? 'repository',
                'kind' => 'repository',
                'error' => $scan->error_message ?: 'Scan failed.',
            ]);

        return $targets->concat($repos)->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function changed(Carbon $since, Carbon $until, FindingStatus $status): array
    {
        return $this->issues()
            ->where('status', $status)
            ->where('updated_at', '>=', $since)
            ->where('updated_at', '<', $until)
            ->limit(15)
            ->get()
            ->map(fn (Finding $finding): array => $this->row($finding))
            ->all();
    }

    /**
     * @return array{severity: string, title: string, subject: string, category: string}
     */
    private function row(Finding $finding): array
    {
        return [
            'severity' => $finding->severity?->value ?? 'low',
            'title' => $finding->title,
            'subject' => $finding->subjectName(),
            'category' => Finding::categoryLabel($finding->category),
        ];
    }

    private function issues()
    {
        return Finding::query()
            ->issues()
            ->with(['asset:id,value', 'repository:id,full_name'])
            ->reorder()
            ->orderByRaw(FindingSeverity::orderByRankSql().' desc')
            ->orderByDesc('updated_at');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function bulletFindings(array $rows, bool $withNote = false): array
    {
        if ($rows === []) {
            return ['- None'];
        }

        return array_map(function (array $row) use ($withNote): string {
            $line = '- ['.$row['severity'].'] '.$row['title'].' — '.$row['subject'];

            if ($withNote && filled($row['note'] ?? null)) {
                $line .= ' — note: '.$row['note'];
            }

            return $line;
        }, $rows);
    }
}
