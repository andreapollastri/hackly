<?php

namespace App\Providers;

use App\Domain\Scanning\Scanners\DnsScanner;
use App\Domain\Scanning\Scanners\NucleiScanner;
use App\Domain\Scanning\Scanners\PathDiscoveryScanner;
use App\Domain\Scanning\Scanners\PortScanner;
use App\Domain\Scanning\Scanners\SubdomainScanner;
use App\Domain\Scanning\Scanners\ZapScanner;
use App\Domain\Scanning\Services\BinaryRunner;
use App\Domain\Scanning\Services\DnsOwnershipVerifier;
use App\Domain\Scanning\Services\ScanDispatcher;
use App\Domain\Scanning\Services\ScannerRegistry;
use App\Domain\Scanning\Services\TargetGuard;
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
                new PortScanner,
                new SubdomainScanner,
                new PathDiscoveryScanner,
                new NucleiScanner,
                new ZapScanner,
            ]);
        });

        $this->app->singleton(ScanDispatcher::class);
    }

    public function boot(): void
    {
        //
    }
}
