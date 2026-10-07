<?php

namespace App\Support;

use App\Domain\Scanning\Services\BinaryRunner;
use Illuminate\Support\Facades\Cache;

/**
 * Cached view of which scanner binaries are installed on this host.
 */
class ScannerHealth
{
    private const CACHE_KEY = 'hackly.scanner-health';

    /**
     * Which scanner tasks depend on each configured binary.
     */
    public const USED_BY = [
        'dig' => ['DNS & WHOIS', 'Mail security'],
        'whois' => ['DNS & WHOIS'],
        'nmap' => ['Port scan'],
        'nuclei' => ['Nuclei templates', 'Path discovery'],
        'zap' => ['OWASP ZAP baseline'],
        'git' => ['Repository clone'],
        'composer' => ['Composer / OSV', 'Composer outdated'],
        'semgrep' => ['Semgrep SAST'],
        'trivy' => ['Trivy SCA'],
        'gitleaks' => ['Gitleaks secrets'],
        'checkov' => ['Checkov IaC'],
    ];

    public const TARGET_BINARIES = ['dig', 'whois', 'nmap', 'nuclei', 'zap'];

    /**
     * @return array<string, array{path: string, available: bool, resolved: ?string, hint: ?string}>
     */
    public static function binaries(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(10),
            fn (): array => app(BinaryRunner::class)->checkConfiguredBinaries(),
        );
    }

    public static function missingCount(): int
    {
        try {
            return collect(self::binaries())->where('available', false)->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
