<?php

namespace App\Filament\Pages;

use App\Filament\Support\NavigationGroup;
use App\Support\ScannerHealth as Health;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use UnitEnum;

class ScannerHealth extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static ?string $navigationLabel = 'Scanner health';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'scanner-health';

    protected string $view = 'filament.pages.scanner-health';

    public static function getNavigationBadge(): ?string
    {
        $missing = Health::missingCount();

        return $missing > 0 ? (string) $missing : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Scanner binaries not found';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Scanner health';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Tools available to the queue worker on this host, plus the safety limits currently enforced.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recheck')
                ->label('Re-check')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (): void {
                    Health::binaries(fresh: true);
                })
                ->successNotificationTitle('Scanner binaries re-checked'),
        ];
    }

    protected function getViewData(): array
    {
        $binaries = collect(Health::binaries())
            ->map(fn (array $row, string $name): array => $row + [
                'name' => $name,
                'used_by' => Health::USED_BY[$name] ?? [],
                'group' => in_array($name, Health::TARGET_BINARIES, true) ? 'target' : 'repository',
            ]);

        $pendingJobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : null;
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null;
        $stalled = Schema::hasTable('jobs')
            ? DB::table('jobs')->where('available_at', '<', now()->subMinutes(5)->getTimestamp())->whereNull('reserved_at')->count()
            : 0;

        return [
            'targetBinaries' => $binaries->where('group', 'target')->values(),
            'repoBinaries' => $binaries->where('group', 'repository')->values(),
            'missing' => $binaries->where('available', false)->count(),
            'queue' => [
                'connection' => (string) config('queue.default'),
                'pending' => $pendingJobs,
                'failed' => $failedJobs,
                'stalled' => $stalled,
            ],
            'safety' => [
                ['label' => 'Ownership verification', 'value' => 'Required (DNS TXT)', 'ok' => true],
                ['label' => 'Allowlist only', 'value' => config('hackly.allowlist_only') ? 'On · '.count(config('hackly.allowlist', [])).' entries' : 'Off', 'ok' => (bool) config('hackly.allowlist_only')],
                ['label' => 'Private / internal targets', 'value' => config('hackly.allow_private_targets') ? 'Allowed' : 'Blocked', 'ok' => ! config('hackly.allow_private_targets')],
                ['label' => 'Requests per target', 'value' => config('hackly.rate_limits.per_target_per_minute').' / minute', 'ok' => true],
                ['label' => 'Concurrent tasks', 'value' => (string) config('hackly.rate_limits.global_concurrent'), 'ok' => true],
                ['label' => 'Task spacing + jitter', 'value' => config('hackly.rate_limits.task_spacing_seconds').'s + up to '.config('hackly.rate_limits.jitter_seconds').'s', 'ok' => true],
                ['label' => 'Deep scan cooldown', 'value' => config('hackly.rate_limits.deep_cooldown_hours').'h per target', 'ok' => true],
                ['label' => 'Quiet hours', 'value' => config('hackly.quiet_hours.enabled')
                    ? sprintf('%02d:00–%02d:00 %s', config('hackly.quiet_hours.start'), config('hackly.quiet_hours.end'), config('hackly.quiet_hours.timezone'))
                    : 'Off', 'ok' => true],
            ],
        ];
    }
}
