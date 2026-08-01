<?php

namespace App\Domain\Scanning\Services;

use App\Domain\Scanning\DTO\BinaryResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class BinaryRunner
{
    /** @var array<string, string|null> */
    private array $resolvedCache = [];

    /**
     * @param  list<string>  $command
     * @param  array<string, string>|null  $env
     */
    public function run(array $command, int $timeout, ?string $outputPath = null, ?array $env = null, ?string $path = null): BinaryResult
    {
        if ($command === []) {
            throw new RuntimeException('Empty command.');
        }

        $resolved = $this->resolveBinaryPath($command[0]);

        if ($resolved === null) {
            throw new RuntimeException("Binary not found or not executable: {$command[0]}");
        }

        $command[0] = $resolved;

        Log::info('hackly.binary.run', [
            'command' => $command,
            'timeout' => $timeout,
            'path' => $path,
            'env_keys' => $env !== null ? array_keys($env) : [],
        ]);

        try {
            $pending = Process::timeout($timeout);

            // Ensure child processes can resolve sibling tools (pipx, etc.).
            $processEnv = array_merge(
                ['PATH' => $this->searchPath()],
                $env ?? [],
            );
            $pending = $pending->env($processEnv);

            if ($path !== null) {
                $pending = $pending->path($path);
            }

            $result = $pending->run($command);
            $stdout = $result->output();
            $stderr = $result->errorOutput();
            $exitCode = $result->exitCode() ?? 1;
        } catch (ProcessTimedOutException $e) {
            $stdout = $e->result->output();
            $stderr = trim($e->result->errorOutput()."\n".$e->getMessage());
            $exitCode = $e->result->exitCode() ?? 124;

            $hasArtifacts = $outputPath !== null && (
                is_file($outputPath)
                || is_file($outputPath.'.xml')
                || is_file($outputPath.'.txt')
                || is_file($outputPath.'.json')
                || is_file($outputPath.'.jsonl')
            );

            Log::warning('hackly.binary.timeout', [
                'command' => $command,
                'timeout' => $timeout,
                'has_artifacts' => $hasArtifacts,
            ]);

            if (! $hasArtifacts && trim($stdout) === '' && trim($e->result->errorOutput()) === '') {
                throw $e;
            }
        }

        if ($outputPath !== null) {
            $dir = dirname($outputPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($outputPath, $stdout."\n--- STDERR ---\n".$stderr);
        }

        return new BinaryResult(
            exitCode: $exitCode,
            stdout: $stdout,
            stderr: $stderr,
            outputPath: $outputPath,
        );
    }

    public function binaryExists(string $binary): bool
    {
        return $this->resolveBinaryPath($binary) !== null;
    }

    /**
     * Resolve a configured binary name/path to an absolute executable path.
     *
     * Interactive shells often include ~/.local/bin (pipx) while PHP CLI /
     * queue workers do not — so bare `which semgrep` falsely reports missing.
     */
    public function resolveBinaryPath(string $binary): ?string
    {
        if (array_key_exists($binary, $this->resolvedCache)) {
            return $this->resolvedCache[$binary];
        }

        if (str_contains($binary, DIRECTORY_SEPARATOR) || str_starts_with($binary, '~')) {
            $expanded = $this->expandHome($binary);

            if ($this->isUsableBinary($expanded)) {
                return $this->resolvedCache[$binary] = $expanded;
            }

            // Wrong absolute path in .env (e.g. /usr/local/bin/trivy while apt put it in /usr/bin).
            $fallback = $this->resolveBinaryByName(basename($expanded));

            return $this->resolvedCache[$binary] = $fallback;
        }

        return $this->resolvedCache[$binary] = $this->resolveBinaryByName($binary);
    }

    private function resolveBinaryByName(string $binary): ?string
    {
        foreach ($this->candidatePaths($binary) as $candidate) {
            if ($this->isUsableBinary($candidate)) {
                return $candidate;
            }
        }

        $searchPath = $this->searchPath();

        $command = Process::env(['PATH' => $searchPath])
            ->run(['bash', '-lc', 'command -v '.escapeshellarg($binary)]);

        if ($command->successful()) {
            $found = trim($command->output());
            if ($found !== '' && $this->isUsableBinary($found)) {
                return $found;
            }
        }

        $which = Process::env(['PATH' => $searchPath])->run(['which', $binary]);

        if ($which->successful()) {
            $found = trim($which->output());
            if ($found !== '' && $this->isUsableBinary($found)) {
                return $found;
            }
        }

        return null;
    }

    private function isUsableBinary(string $path): bool
    {
        if ($path === '' || is_dir($path)) {
            return false;
        }

        // Broken symlink
        if (is_link($path) && ! file_exists($path)) {
            return false;
        }

        return is_file($path) && is_executable($path);
    }

    /**
     * @return array<string, array{path: string, available: bool, resolved: ?string, hint: ?string}>
     */
    public function checkConfiguredBinaries(): array
    {
        $status = [];

        foreach (config('hackly.binaries') as $name => $path) {
            $configured = (string) $path;
            $resolved = $this->resolveBinaryPath($configured);
            $hint = null;

            if ($resolved === null) {
                $hint = $this->missingBinaryHint($configured);
            }

            $status[$name] = [
                'path' => $configured,
                'available' => $resolved !== null,
                'resolved' => $resolved,
                'hint' => $hint,
            ];
        }

        return $status;
    }

    private function missingBinaryHint(string $binary): ?string
    {
        $name = str_contains($binary, DIRECTORY_SEPARATOR)
            ? basename($binary)
            : $binary;

        if (str_contains($binary, DIRECTORY_SEPARATOR) || str_starts_with($binary, '~')) {
            $expanded = $this->expandHome($binary);

            if (is_link($expanded) && ! file_exists($expanded)) {
                return "broken symlink at {$expanded} — remove it and reinstall, or set HACKLY_".strtoupper($name).' to the real path (try: command -v '.$name.')';
            }

            if (! file_exists($expanded)) {
                return "{$expanded} does not exist — run: command -v {$name}  (apt often installs trivy in /usr/bin/trivy)";
            }

            if (! is_executable($expanded)) {
                return "{$expanded} exists but is not executable by this PHP user — chmod a+rx or fix ownership";
            }
        }

        $shadows = [
            '/usr/bin/'.$name,
            '/usr/local/bin/'.$name,
            '/root/.local/bin/'.$name,
            (getenv('HOME') ?: '').'/.local/bin/'.$name,
        ];

        foreach ($shadows as $shadow) {
            if ($shadow === '' || $shadow === '/.local/bin/'.$name) {
                continue;
            }

            if (! file_exists($shadow) && ! is_link($shadow)) {
                continue;
            }

            $target = is_link($shadow) ? (readlink($shadow) ?: $shadow) : $shadow;
            $real = realpath($shadow) ?: $target;

            if (str_starts_with((string) $real, '/root/') || str_starts_with((string) $target, '/root/')) {
                return "found {$shadow} but it lives under /root (not runnable by this PHP user). Re-run: sudo bash scripts/install-repo-scanners.sh";
            }

            if ($this->isUsableBinary($shadow)) {
                return "usable at {$shadow} — set HACKLY_".strtoupper($name)."={$shadow} or remove the wrong absolute path from .env";
            }

            if (! is_executable($shadow)) {
                return "found {$shadow} but not executable by this PHP user";
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function candidatePaths(string $binary): array
    {
        $homes = array_values(array_unique(array_filter([
            getenv('HOME') ?: null,
            getenv('USERPROFILE') ?: null,
            '/root',
            '/home/'.(getenv('SUDO_USER') ?: ''),
            '/var/www',
        ])));

        $dirs = array_merge(
            (array) config('hackly.binary_search_dirs', []),
            [
                '/usr/local/bin',
                '/usr/bin',
                '/bin',
                '/opt/homebrew/bin',
                '/usr/local/sbin',
            ],
        );

        foreach ($homes as $home) {
            if (! is_string($home) || $home === '' || $home === '/home/') {
                continue;
            }
            $dirs[] = rtrim($home, '/').'/.local/bin';
            $dirs[] = rtrim($home, '/').'/.cargo/bin';
        }

        // pipx / install script defaults
        $dirs[] = '/root/.local/bin';

        $dirs = array_values(array_unique(array_filter($dirs)));

        return array_map(fn (string $dir) => rtrim($dir, '/').'/'.$binary, $dirs);
    }

    private function searchPath(): string
    {
        $dirs = [];

        foreach ($this->candidatePaths('__placeholder__') as $candidate) {
            $dirs[] = dirname($candidate);
        }

        $existing = getenv('PATH') ?: '';
        if ($existing !== '') {
            $dirs = array_merge($dirs, explode(PATH_SEPARATOR, $existing));
        }

        return implode(PATH_SEPARATOR, array_values(array_unique(array_filter($dirs))));
    }

    private function expandHome(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            $home = getenv('HOME') ?: '/root';

            return $home.substr($path, 1);
        }

        return $path;
    }
}
