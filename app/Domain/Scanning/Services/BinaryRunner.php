<?php

namespace App\Domain\Scanning\Services;

use App\Domain\Scanning\DTO\BinaryResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class BinaryRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>|null  $env
     */
    public function run(array $command, int $timeout, ?string $outputPath = null, ?array $env = null, ?string $path = null): BinaryResult
    {
        if ($command === []) {
            throw new RuntimeException('Empty command.');
        }

        $binary = $command[0];

        if (! $this->binaryExists($binary)) {
            throw new RuntimeException("Binary not found or not executable: {$binary}");
        }

        Log::info('hackly.binary.run', [
            'command' => $command,
            'timeout' => $timeout,
            'path' => $path,
            'env_keys' => $env !== null ? array_keys($env) : [],
        ]);

        try {
            $pending = Process::timeout($timeout);

            if ($env !== null) {
                $pending = $pending->env($env);
            }

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
        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            return is_executable($binary);
        }

        $which = Process::run(['which', $binary]);

        return $which->successful() && filled(trim($which->output()));
    }

    /**
     * @return array<string, array{path: string, available: bool}>
     */
    public function checkConfiguredBinaries(): array
    {
        $status = [];

        foreach (config('hackly.binaries') as $name => $path) {
            $status[$name] = [
                'path' => $path,
                'available' => $this->binaryExists($path),
            ];
        }

        return $status;
    }
}
