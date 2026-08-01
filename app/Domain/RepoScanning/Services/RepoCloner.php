<?php

namespace App\Domain\RepoScanning\Services;

use App\Models\RepoScan;
use App\Models\Repository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class RepoCloner
{
    public function __construct(
        private readonly GithubClient $github,
    ) {}

    /**
     * @return array{path: string, commit_sha: string}
     */
    public function cloneForScan(Repository $repository, RepoScan $scan): array
    {
        $base = rtrim((string) config('hackly.repo.workspace_path', storage_path('app/repo-scans')), '/');
        $path = $base.'/'.$scan->id;

        if (is_dir($path)) {
            File::deleteDirectory($path);
        }

        File::ensureDirectoryExists($base);

        $branch = $repository->default_branch ?: 'main';
        $url = $this->github->authenticatedCloneUrl($repository);
        $timeout = (int) config('hackly.repo.clone_timeout', 300);
        $depth = (int) config('hackly.repo.clone_depth', 1);

        $command = [
            'git', 'clone',
            '--depth', (string) max(1, $depth),
            '--branch', $branch,
            '--single-branch',
            $url,
            $path,
        ];

        Log::info('hackly.repo.clone.start', [
            'repository' => $repository->full_name,
            'branch' => $branch,
            'scan_id' => $scan->id,
        ]);

        $result = Process::timeout($timeout)
            ->env([
                'GIT_TERMINAL_PROMPT' => '0',
                'GIT_ASKPASS' => 'echo',
            ])
            ->run($command);

        if (! $result->successful()) {
            File::deleteDirectory($path);
            $stderr = $this->redactToken($result->errorOutput() ?: $result->output(), $repository);

            throw new RuntimeException('git clone failed: '.substr($stderr, 0, 1000));
        }

        $shaResult = Process::path($path)->run(['git', 'rev-parse', 'HEAD']);
        $sha = trim($shaResult->output());

        if ($sha === '') {
            $sha = 'unknown';
        }

        $scan->update([
            'workspace_path' => $path,
            'commit_sha' => $sha,
        ]);

        $repository->update([
            'last_commit_sha' => $sha,
        ]);

        return ['path' => $path, 'commit_sha' => $sha];
    }

    public function cleanup(RepoScan $scan): void
    {
        $path = $scan->workspace_path;

        if (! is_string($path) || $path === '' || ! is_dir($path)) {
            return;
        }

        $base = realpath((string) config('hackly.repo.workspace_path', storage_path('app/repo-scans')));
        $real = realpath($path);

        if ($base === false || $real === false || ! str_starts_with($real, $base)) {
            Log::warning('hackly.repo.cleanup.refused', ['path' => $path]);

            return;
        }

        File::deleteDirectory($real);
        $scan->update(['workspace_path' => null]);
    }

    private function redactToken(string $output, Repository $repository): string
    {
        $token = $repository->credential?->token;

        if (! filled($token)) {
            return $output;
        }

        return str_replace($token, '***', $output);
    }
}
