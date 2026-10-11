<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ScannerDatabaseUpdateTest extends TestCase
{
    public function test_missing_tools_are_skipped(): void
    {
        config([
            'hackly.binaries.nuclei' => '/nonexistent/hackly-nuclei',
            'hackly.binaries.trivy' => '/nonexistent/hackly-trivy',
            'hackly.binaries.semgrep' => '/nonexistent/hackly-semgrep',
        ]);

        $this->artisan('hackly:update-databases')
            ->assertSuccessful()
            ->expectsOutputToContain('skipped');
    }

    public function test_installed_tools_are_refreshed_without_calling_the_network(): void
    {
        $dir = storage_path('framework/testing/fake-scanners');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        foreach (['nuclei', 'trivy', 'semgrep'] as $name) {
            $path = $dir.'/'.$name;
            file_put_contents($path, "#!/bin/sh\nexit 0\n");
            chmod($path, 0755);
        }

        config([
            'hackly.binaries.nuclei' => $dir.'/nuclei',
            'hackly.binaries.trivy' => $dir.'/trivy',
            'hackly.binaries.semgrep' => $dir.'/semgrep',
            'hackly.repo.trivy.cache' => $dir.'/trivy-cache',
            'hackly.repo.semgrep.home' => $dir.'/semgrep-home',
            'hackly.nuclei.home' => $dir.'/nuclei-home',
            'hackly.nuclei.templates_path' => $dir.'/nuclei-templates',
        ]);

        Process::fake();

        $this->artisan('hackly:update-databases')->assertSuccessful();

        Process::assertRan(function ($process): bool {
            $command = implode(' ', (array) $process->command);

            return str_contains($command, '-update-templates');
        });

        Process::assertRan(function ($process): bool {
            $command = implode(' ', (array) $process->command);

            return str_contains($command, '--download-db-only');
        });

        Process::assertRan(function ($process): bool {
            $command = implode(' ', (array) $process->command);

            return str_contains($command, '--config') && str_contains($command, 'p/php');
        });
    }

    public function test_unknown_tool_is_rejected(): void
    {
        $this->artisan('hackly:update-databases', ['--only' => 'nmap'])
            ->assertFailed();
    }
}
