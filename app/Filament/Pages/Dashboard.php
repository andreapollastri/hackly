<?php

namespace App\Filament\Pages;

use App\Filament\Actions\StartRepositoryScanAction;
use App\Filament\Actions\StartTargetScanAction;
use App\Filament\Widgets\FindingsTrendChart;
use App\Filament\Widgets\GettingStarted;
use App\Filament\Widgets\IssuesBySourceChart;
use App\Filament\Widgets\PostureStats;
use App\Filament\Widgets\PriorityFindings;
use App\Filament\Widgets\RecentRepoScans;
use App\Filament\Widgets\RecentTargetScans;
use App\Models\Asset;
use App\Models\Repository;
use Filament\Actions\ActionGroup;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Overview';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    public function getSubheading(): string|Htmlable|null
    {
        $targets = Asset::query()->count();
        $repos = Repository::query()->count();

        if ($targets + $repos === 0) {
            return 'Welcome to Hackly — let’s get your first asset under watch.';
        }

        return sprintf(
            'Security posture across %d %s and %d %s.',
            $targets,
            str('target')->plural($targets),
            $repos,
            str('repository')->plural($repos),
        );
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'md' => 2, 'xl' => 4];
    }

    public function getWidgets(): array
    {
        return [
            GettingStarted::class,
            PostureStats::class,
            FindingsTrendChart::class,
            IssuesBySourceChart::class,
            PriorityFindings::class,
            RecentTargetScans::class,
            RecentRepoScans::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                StartTargetScanAction::make('scanTarget', pickTarget: true)
                    ->icon(Heroicon::OutlinedGlobeAlt),
                StartRepositoryScanAction::make('scanRepository', pickRepository: true)
                    ->icon(Heroicon::OutlinedCodeBracketSquare),
            ])
                ->label('New scan')
                ->icon(Heroicon::OutlinedPlay)
                ->color('primary')
                ->button(),
        ];
    }
}
