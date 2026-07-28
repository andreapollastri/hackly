<?php

namespace App\Domain\Scanning\Services;

use App\Domain\Scanning\DTO\BinaryResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class BinaryRunner
{
    /**
     * @param  list<string>  $command
     */
    public function run(array $command, int $timeout, ?string $outputPath = null): BinaryResult
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
        ]);

        $result = Process::timeout($timeout)->run($command);

        $stdout = $result->output();
        $stderr = $result->errorOutput();

        if ($outputPath !== null) {
            $dir = dirname($outputPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($outputPath, $stdout."\n--- STDERR ---\n".$stderr);
        }

        return new BinaryResult(
            exitCode: $result->exitCode() ?? 1,
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
