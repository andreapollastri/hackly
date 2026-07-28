<?php

namespace App\Filament\Widgets;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\ScanStatus;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Scans\ScanResource;
use App\Models\Asset;
use App\Models\Finding;
use App\Models\Scan;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class HacklyStatsOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $totalTargets = Asset::query()->count();
        $unverifiedTargets = Asset::query()->whereNull('verified_at')->count();
        $totalScans = Scan::query()->count();
        $runningScans = Scan::query()
            ->whereIn('status', [ScanStatus::Pending, ScanStatus::Running])
            ->count();
        $openHighFindings = Finding::query()
            ->where('status', FindingStatus::Open)
            ->where('severity', FindingSeverity::High)
            ->count();

        return [
            Stat::make('Target totali', number_format($totalTargets))
                ->description('Asset in perimetro')
                ->descriptionIcon(Heroicon::OutlinedGlobeAlt)
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->color('primary')
                ->url(AssetResource::getUrl('index')),

            Stat::make('Target da verificare', number_format($unverifiedTargets))
                ->description('Ownership non confermata')
                ->descriptionIcon(Heroicon::OutlinedShieldExclamation)
                ->icon(Heroicon::OutlinedShieldExclamation)
                ->color($unverifiedTargets > 0 ? 'warning' : 'success')
                ->url(AssetResource::getUrl('index')),

            Stat::make('Scansioni totali', number_format($totalScans))
                ->description($runningScans > 0
                    ? "{$runningScans} in corso"
                    : 'Nessuna in corso')
                ->descriptionIcon($runningScans > 0
                    ? Heroicon::OutlinedArrowPath
                    : Heroicon::OutlinedCheckCircle)
                ->icon(Heroicon::OutlinedQueueList)
                ->color('info')
                ->url(ScanResource::getUrl('index')),

            Stat::make('Finding high aperti', number_format($openHighFindings))
                ->description('Severità alta da gestire')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->icon(Heroicon::OutlinedBugAnt)
                ->color($openHighFindings > 0 ? 'danger' : 'success')
                ->url(ScanResource::getUrl('index')),
        ];
    }
}
