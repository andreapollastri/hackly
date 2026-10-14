<?php

namespace App\Providers;

use App\Domain\RepoScanning\Scanners\CheckovScanner;
use App\Domain\RepoScanning\Scanners\CheckpointScanner;
use App\Domain\RepoScanning\Scanners\ComposerOsvScanner;
use App\Domain\RepoScanning\Scanners\ComposerOutdatedScanner;
use App\Domain\RepoScanning\Scanners\GitleaksScanner;
use App\Domain\RepoScanning\Scanners\LaravelLivePentestScanner;
use App\Domain\RepoScanning\Scanners\LaravelPhpAuditScanner;
use App\Domain\RepoScanning\Scanners\SemgrepScanner;
use App\Domain\RepoScanning\Scanners\TrivyScanner;
use App\Domain\RepoScanning\Services\FindingDeduplicator;
use App\Domain\RepoScanning\Services\GithubClient;
use App\Domain\RepoScanning\Services\NoiseFilter;
use App\Domain\RepoScanning\Services\PhpReachabilityAnalyzer;
use App\Domain\RepoScanning\Services\RepoCloner;
use App\Domain\RepoScanning\Services\RepoScanDispatcher;
use App\Domain\RepoScanning\Services\RepoScannerRegistry;
use App\Domain\Scanning\Scanners\DnsScanner;
use App\Domain\Scanning\Scanners\MailSecurityScanner;
use App\Domain\Scanning\Scanners\NiktoScanner;
use App\Domain\Scanning\Scanners\NucleiPentestScanner;
use App\Domain\Scanning\Scanners\NucleiScanner;
use App\Domain\Scanning\Scanners\OriginExposureScanner;
use App\Domain\Scanning\Scanners\PathDiscoveryScanner;
use App\Domain\Scanning\Scanners\PentestApiScanner;
use App\Domain\Scanning\Scanners\PentestLoginScanner;
use App\Domain\Scanning\Scanners\PentestPortScanner;
use App\Domain\Scanning\Scanners\PentestSurfaceScanner;
use App\Domain\Scanning\Scanners\PortScanner;
use App\Domain\Scanning\Scanners\SubdomainScanner;
use App\Domain\Scanning\Scanners\TechFingerprintScanner;
use App\Domain\Scanning\Scanners\TlsScanner;
use App\Domain\Scanning\Scanners\WpScanScanner;
use App\Domain\Scanning\Scanners\ZapPentestScanner;
use App\Domain\Scanning\Scanners\ZapScanner;
use App\Domain\Scanning\Services\BinaryRunner;
use App\Domain\Scanning\Services\DnsOwnershipVerifier;
use App\Domain\Scanning\Services\ScanDispatcher;
use App\Domain\Scanning\Services\ScannerRegistry;
use App\Domain\Scanning\Services\TargetGuard;
use App\Updates\Updater;
use App\Updates\UpdateState;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BinaryRunner::class);
        $this->app->singleton(TargetGuard::class);
        $this->app->singleton(DnsOwnershipVerifier::class);

        $this->app->singleton(ScannerRegistry::class, function () {
            return new ScannerRegistry([
                new DnsScanner,
                new MailSecurityScanner,
                new TlsScanner,
                new PortScanner,
                new SubdomainScanner,
                new OriginExposureScanner,
                new TechFingerprintScanner,
                new PathDiscoveryScanner,
                new NucleiScanner,
                new NucleiPentestScanner,
                new ZapScanner,
                new ZapPentestScanner,
                new PentestPortScanner,
                new PentestApiScanner,
                new PentestLoginScanner,
                new PentestSurfaceScanner,
                new WpScanScanner,
                new NiktoScanner,
            ]);
        });

        $this->app->singleton(ScanDispatcher::class);

        $this->app->singleton(GithubClient::class);
        $this->app->singleton(RepoCloner::class);
        $this->app->singleton(FindingDeduplicator::class);
        $this->app->singleton(NoiseFilter::class);
        $this->app->singleton(PhpReachabilityAnalyzer::class);

        $this->app->singleton(RepoScannerRegistry::class, function () {
            return new RepoScannerRegistry([
                new SemgrepScanner,
                new TrivyScanner,
                new GitleaksScanner,
                new CheckovScanner,
                new ComposerOsvScanner,
                new ComposerOutdatedScanner,
                new LaravelPhpAuditScanner,
                new CheckpointScanner,
                new LaravelLivePentestScanner,
            ]);
        });

        $this->app->singleton(RepoScanDispatcher::class);

        $this->app->singleton(Updater::class, fn (): Updater => new Updater(new UpdateState((string) config('updates.state'))));
    }

    public function boot(): void
    {
        config(['sanctum.token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'hk_')]);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });
    }
}
