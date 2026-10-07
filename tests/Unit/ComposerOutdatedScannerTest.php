<?php

namespace Tests\Unit;

use App\Domain\RepoScanning\Scanners\ComposerOutdatedScanner;
use App\Enums\FindingSeverity;
use PHPUnit\Framework\TestCase;

class ComposerOutdatedScannerTest extends TestCase
{
    public function test_it_turns_an_outdated_report_into_low_noise_findings(): void
    {
        $findings = ComposerOutdatedScanner::findingsFromReport(['locked' => [
            ['name' => 'laravel/framework', 'version' => 'v11.44.1', 'latest' => 'v13.35.0', 'latest-status' => 'update-possible', 'abandoned' => false],
            ['name' => 'spatie/laravel-permission', 'version' => '5.11.1', 'latest' => '6.21.0', 'latest-status' => 'update-possible', 'abandoned' => false],
            ['name' => 'fruitcake/laravel-cors', 'version' => 'v3.0.0', 'latest' => 'v3.0.0', 'latest-status' => 'up-to-date', 'abandoned' => 'laravel/framework'],
            ['name' => 'guzzlehttp/guzzle', 'version' => '7.8.1', 'latest' => '7.10.0', 'latest-status' => 'semver-safe-update', 'abandoned' => false],
            ['name' => 'filament/filament', 'version' => 'v5.7.6', 'latest' => 'v5.10.0', 'latest-status' => 'semver-safe-update', 'abandoned' => false],
            ['name' => 'barryvdh/laravel-dompdf', 'version' => 'v3.1.2', 'latest' => 'v3.1.2', 'latest-status' => 'up-to-date', 'abandoned' => false],
        ]]);

        $byKey = collect($findings)->keyBy(fn ($f) => $f->resolvedDedupeKey());

        $this->assertCount(4, $findings);

        $laravel = $byKey['composer-outdated-major|laravel/framework'];
        $this->assertSame(FindingSeverity::Medium, $laravel->severity);
        $this->assertSame('Laravel 11 is out of security support (latest 13)', $laravel->title);

        $spatie = $byKey['composer-outdated-major|spatie/laravel-permission'];
        $this->assertSame(FindingSeverity::Low, $spatie->severity);
        $this->assertStringContainsString('1 major version behind (5.11.1 → 6.21.0)', $spatie->title);

        $abandoned = $byKey['composer-abandoned|fruitcake/laravel-cors'];
        $this->assertSame(FindingSeverity::Medium, $abandoned->severity);
        $this->assertStringContainsString('recommends laravel/framework', (string) $abandoned->description);

        $compatible = $byKey['composer-outdated-compatible'];
        $this->assertSame(FindingSeverity::Low, $compatible->severity);
        $this->assertSame('2 direct dependencies have compatible updates', $compatible->title);
        $this->assertSame(['guzzlehttp/guzzle 7.8.1 → 7.10.0', 'filament/filament 5.7.6 → 5.10.0'], $compatible->evidence['records']);
    }

    public function test_an_up_to_date_project_produces_no_findings(): void
    {
        $this->assertSame([], ComposerOutdatedScanner::findingsFromReport(['locked' => [
            ['name' => 'laravel/framework', 'version' => 'v13.35.0', 'latest' => 'v13.35.0', 'latest-status' => 'up-to-date', 'abandoned' => false],
        ]]));

        $this->assertSame([], ComposerOutdatedScanner::findingsFromReport([]));
    }

    public function test_major_gap_handles_prefixes_and_zero_major_versions(): void
    {
        $this->assertSame(2, ComposerOutdatedScanner::majorGap('v11.0.0', 'v13.1.0'));
        $this->assertSame(0, ComposerOutdatedScanner::majorGap('13.29.0', 'v13.35.0'));
        $this->assertSame(3, ComposerOutdatedScanner::majorGap('0.2.9', '0.5.0'));
        $this->assertSame(0, ComposerOutdatedScanner::majorGap('dev-main', 'dev-main'));
    }
}
