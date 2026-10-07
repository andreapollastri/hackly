<?php

namespace App\Filament\Widgets;

use App\Enums\FindingSeverity;
use App\Enums\ScanStatus;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Findings\FindingResource;
use App\Filament\Resources\Scans\ScanResource;
use App\Models\Asset;
use App\Models\Finding;
use App\Models\RepoScan;
use App\Models\Repository;
use App\Models\Scan;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PostureStats extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected ?string $pollingInterval = '30s';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $open = Finding::query()
            ->issues()
            ->open()
            ->reorder()
            ->selectRaw('severity, count(*) as aggregate')
            ->groupBy('severity')
            ->pluck('aggregate', 'severity');

        $high = (int) ($open[FindingSeverity::High->value] ?? 0);
        $medium = (int) ($open[FindingSeverity::Medium->value] ?? 0);
        $low = (int) ($open[FindingSeverity::Low->value] ?? 0);

        $since = now()->subDays(13)->startOfDay();
        $recent = Finding::query()
            ->issues()
            ->reorder()
            ->where('created_at', '>=', $since)
            ->get(['severity', 'created_at']);

        $newHighThisWeek = $recent
            ->where('severity', FindingSeverity::High)
            ->filter(fn (Finding $f) => $f->created_at->gte(now()->subDays(7)))
            ->count();

        $targets = Asset::query()->count();
        $verified = Asset::query()->whereNotNull('verified_at')->count();
        $repos = Repository::query()->count();

        $running = Scan::query()->whereIn('status', ScanStatus::active())->count()
            + RepoScan::query()->whereIn('status', ScanStatus::active())->count();
        $scans30 = Scan::query()->where('created_at', '>=', now()->subDays(30))->count()
            + RepoScan::query()->where('created_at', '>=', now()->subDays(30))->count();
        $scanDates = Scan::query()->where('created_at', '>=', $since)->pluck('created_at')
            ->merge(RepoScan::query()->where('created_at', '>=', $since)->pluck('created_at'));

        $findingsUrl = fn (string $severity): string => FindingResource::getUrl('index', [
            'tab' => 'open',
            'filters' => ['severity' => ['values' => [$severity]]],
        ]);

        return [
            Stat::make('Open high-severity', number_format($high))
                ->description($newHighThisWeek > 0 ? "+{$newHighThisWeek} new in the last 7 days" : 'Nothing new this week')
                ->descriptionIcon($newHighThisWeek > 0 ? Heroicon::OutlinedArrowTrendingUp : Heroicon::OutlinedCheckCircle)
                ->icon(Heroicon::OutlinedFire)
                ->color($high > 0 ? 'danger' : 'success')
                ->chart($this->dailySeries($recent->where('severity', FindingSeverity::High)->pluck('created_at'), $since))
                ->url($findingsUrl(FindingSeverity::High->value)),

            Stat::make('Open medium & low', number_format($medium + $low))
                ->description("{$medium} medium · {$low} low")
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->icon(Heroicon::OutlinedBugAnt)
                ->color($medium > 0 ? 'warning' : 'gray')
                ->chart($this->dailySeries($recent->where('severity', '!=', FindingSeverity::High)->pluck('created_at'), $since))
                ->url($findingsUrl(FindingSeverity::Medium->value)),

            Stat::make('Assets monitored', number_format($targets + $repos))
                ->description("{$verified}/{$targets} targets verified · {$repos} ".str('repo')->plural($repos))
                ->descriptionIcon($verified < $targets ? Heroicon::OutlinedShieldExclamation : Heroicon::OutlinedShieldCheck)
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->color($verified < $targets ? 'warning' : 'primary')
                ->url(AssetResource::getUrl('index')),

            Stat::make('Scans · last 30 days', number_format($scans30))
                ->description($running > 0 ? "{$running} running right now" : 'No scan running')
                ->descriptionIcon($running > 0 ? Heroicon::OutlinedArrowPath : Heroicon::OutlinedPauseCircle)
                ->icon(Heroicon::OutlinedQueueList)
                ->color($running > 0 ? 'info' : 'gray')
                ->chart($this->dailySeries($scanDates, $since))
                ->url(ScanResource::getUrl('index')),
        ];
    }

    /**
     * @param  Collection<int, Carbon>  $dates
     * @return list<int>
     */
    private function dailySeries(Collection $dates, Carbon $since): array
    {
        $buckets = $dates->countBy(fn (Carbon $date): string => $date->toDateString());

        return collect(range(0, 13))
            ->map(fn (int $i): int => (int) ($buckets[$since->copy()->addDays($i)->toDateString()] ?? 0))
            ->all();
    }
}
