<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ScannerHealth as ScannerHealthPage;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\GithubCredentials\GithubCredentialResource;
use App\Filament\Resources\Repositories\RepositoryResource;
use App\Filament\Resources\Scans\ScanResource;
use App\Models\Asset;
use App\Models\GithubCredential;
use App\Models\RepoScan;
use App\Models\Repository;
use App\Models\Scan;
use App\Support\ScannerHealth;
use Filament\Widgets\Widget;

class GettingStarted extends Widget
{
    protected string $view = 'filament.widgets.getting-started';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -10;

    public static function canView(): bool
    {
        return collect(static::steps())->contains(fn (array $step): bool => ! $step['done']);
    }

    /**
     * @return list<array{title: string, text: string, done: bool, url: string, cta: string}>
     */
    public static function steps(): array
    {
        return once(fn (): array => [
            [
                'title' => 'Add a target',
                'text' => 'A domain you own and are allowed to test.',
                'done' => Asset::query()->exists(),
                'url' => AssetResource::getUrl('index'),
                'cta' => 'Add target',
            ],
            [
                'title' => 'Verify ownership',
                'text' => 'Publish a DNS TXT token to unlock scanning.',
                'done' => Asset::query()->whereNotNull('verified_at')->exists(),
                'url' => AssetResource::getUrl('index', ['filters' => ['verified_at' => ['value' => '0']]]),
                'cta' => 'Verify',
            ],
            [
                'title' => 'Run a first scan',
                'text' => 'Quick profile: DNS, mail, TLS, ports — in minutes.',
                'done' => Scan::query()->exists(),
                'url' => ScanResource::getUrl('index'),
                'cta' => 'Start scan',
            ],
            [
                'title' => 'Connect GitHub',
                'text' => 'A read-only token to clone your repositories.',
                'done' => GithubCredential::query()->exists(),
                'url' => GithubCredentialResource::getUrl('index'),
                'cta' => 'Add token',
            ],
            [
                'title' => 'Scan a repository',
                'text' => 'SAST, dependencies, secrets and Laravel checks.',
                'done' => Repository::query()->exists() && RepoScan::query()->exists(),
                'url' => RepositoryResource::getUrl('index'),
                'cta' => 'Add repository',
            ],
        ]);
    }

    protected function getViewData(): array
    {
        $steps = static::steps();

        return [
            'steps' => $steps,
            'done' => collect($steps)->where('done', true)->count(),
            'missingScanners' => ScannerHealth::missingCount(),
            'healthUrl' => ScannerHealthPage::getUrl(),
        ];
    }
}
