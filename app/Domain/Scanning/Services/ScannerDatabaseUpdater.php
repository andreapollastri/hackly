<?php

namespace App\Domain\Scanning\Services;

use App\Domain\Scanning\DTO\BinaryResult;
use App\Domain\Scanning\Support\NucleiRuntime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes the local data those scanners keep on disk.
 * Composer audit and OSV are queried live at scan time and have nothing to update here.
 */
class ScannerDatabaseUpdater
{
    public const CACHE_KEY = 'hackly.database-updates';

    public function __construct(private readonly BinaryRunner $runner) {}

    /**
     * @param  list<string>|null  $only
     * @return list<array{tool: string, status: string, detail: string}>
     */
    public function update(?array $only = null): array
    {
        $steps = [
            'nuclei' => fn (): array => $this->nuclei(),
            'trivy' => fn (): array => $this->trivy(),
            'semgrep' => fn (): array => $this->semgrep(),
        ];

        if ($only !== null) {
            $steps = array_intersect_key($steps, array_flip($only));
        }

        $results = [];

        foreach ($steps as $callback) {
            $results[] = $callback();
        }

        Cache::forever(self::CACHE_KEY, [
            'ran_at' => now()->toIso8601String(),
            'results' => $results,
        ]);

        return $results;
    }

    /**
     * @return array{ran_at: string, results: list<array{tool: string, status: string, detail: string}>}|null
     */
    public static function lastRun(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        return is_array($cached) ? $cached : null;
    }

    /**
     * @return array{tool: string, status: string, detail: string}
     */
    private function nuclei(): array
    {
        $binary = (string) config('hackly.binaries.nuclei');

        if (! $this->runner->binaryExists($binary)) {
            return $this->row('nuclei', 'skipped', 'Binary not installed.');
        }

        $result = $this->runner->run(
            [$binary, '-update-templates', '-duc', '-nc', '-ud', NucleiRuntime::templatesDirectory()],
            timeout: $this->timeout(),
            env: NucleiRuntime::environment(),
            path: NucleiRuntime::home(),
        );

        return $this->fromResult('nuclei', $result, 'Templates are current.');
    }

    /**
     * @return array{tool: string, status: string, detail: string}
     */
    private function trivy(): array
    {
        $binary = (string) config('hackly.binaries.trivy');

        if (! $this->runner->binaryExists($binary)) {
            return $this->row('trivy', 'skipped', 'Binary not installed.');
        }

        $cache = $this->ensureDirectory((string) config('hackly.repo.trivy.cache'));

        $result = $this->runner->run(
            [$binary, 'fs', '--download-db-only', '--cache-dir', $cache, '--quiet', $cache],
            timeout: $this->timeout(),
            env: [
                'HOME' => dirname($cache),
                'XDG_CACHE_HOME' => dirname($cache),
            ],
            path: $cache,
        );

        return $this->fromResult('trivy', $result, 'Vulnerability database is current.');
    }

    /**
     * @return array{tool: string, status: string, detail: string}
     */
    private function semgrep(): array
    {
        $binary = (string) config('hackly.binaries.semgrep');

        if (! $this->runner->binaryExists($binary)) {
            return $this->row('semgrep', 'skipped', 'Binary not installed.');
        }

        $home = $this->ensureDirectory((string) config('hackly.repo.semgrep.home'));
        $refresh = $this->ensureDirectory($home.DIRECTORY_SEPARATOR.'refresh');
        File::put($refresh.DIRECTORY_SEPARATOR.'ping.php', "<?php\n");

        foreach ([$home.'/.cache/semgrep', $home.'/.semgrep'] as $stale) {
            if (is_dir($stale)) {
                File::deleteDirectory($stale);
            }
        }

        $result = $this->runner->run(
            [
                $binary, 'scan',
                '--config', (string) config('hackly.repo.semgrep.config', 'p/php'),
                '--metrics', 'off',
                '--quiet',
                $refresh,
            ],
            timeout: $this->timeout(),
            env: self::semgrepEnvironment($home),
            path: $home,
        );

        if (in_array($result->exitCode, [0, 1], true)) {
            return $this->row('semgrep', 'updated', 'Rules refreshed.');
        }

        return $this->failed('semgrep', $result);
    }

    /**
     * @return array<string, string>
     */
    public static function semgrepEnvironment(string $home): array
    {
        return [
            'HOME' => $home,
            'XDG_CACHE_HOME' => $home.DIRECTORY_SEPARATOR.'.cache',
            'SEMGREP_SEND_METRICS' => 'off',
        ];
    }

    /**
     * @return array{tool: string, status: string, detail: string}
     */
    private function fromResult(string $tool, BinaryResult $result, string $success): array
    {
        if ($result->exitCode === 0) {
            return $this->row($tool, 'updated', $success);
        }

        return $this->failed($tool, $result);
    }

    /**
     * @return array{tool: string, status: string, detail: string}
     */
    private function failed(string $tool, BinaryResult $result): array
    {
        $detail = trim($result->stderr ?: $result->stdout);
        $detail = $detail !== '' ? $detail : 'Exit '.$result->exitCode;

        Log::warning('hackly.databases.update_failed', ['tool' => $tool, 'exit' => $result->exitCode, 'detail' => $detail]);

        return $this->row($tool, 'failed', str($detail)->limit(240)->toString());
    }

    /**
     * @return array{tool: string, status: string, detail: string}
     */
    private function row(string $tool, string $status, string $detail): array
    {
        return ['tool' => $tool, 'status' => $status, 'detail' => $detail];
    }

    private function timeout(): int
    {
        return max(60, (int) config('hackly.databases.timeout', 900));
    }

    private function ensureDirectory(string $path): string
    {
        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }

        return $path;
    }
}
