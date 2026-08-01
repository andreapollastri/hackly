<?php

namespace App\Console\Commands;

use App\Domain\RepoScanning\Services\RepoScanDispatcher;
use App\Enums\ScanProfile;
use App\Models\Repository;
use Illuminate\Console\Command;

class HacklyRepoScanCommand extends Command
{
    protected $signature = 'hackly:repo-scan
        {repository? : Repository UUID or owner/name}
        {--profile=standard : quick|standard|deep}
        {--include-targets : Also deep-scan all linked targets}
        {--target-profile=deep : Profile for linked targets when --include-targets}
        {--nightly : Deprecated — use hackly:nightly}';

    protected $description = 'Start a GitHub repository security scan (optionally including linked targets)';

    public function handle(RepoScanDispatcher $dispatcher): int
    {
        if ($this->option('nightly')) {
            $this->warn('hackly:repo-scan --nightly is deprecated. Running hackly:nightly instead.');

            return $this->call('hackly:nightly', [
                '--repo-profile' => (string) $this->option('profile'),
                '--target-profile' => (string) $this->option('target-profile'),
            ]);
        }

        $profile = ScanProfile::tryFrom((string) $this->option('profile')) ?? ScanProfile::Standard;
        $includeTargets = (bool) $this->option('include-targets');
        $targetProfile = ScanProfile::tryFrom((string) $this->option('target-profile')) ?? ScanProfile::Deep;

        $key = $this->argument('repository');

        if (! filled($key)) {
            $this->error('Provide a repository UUID/full_name, or use hackly:nightly.');

            return self::FAILURE;
        }

        $repo = Repository::query()
            ->with('credential')
            ->where(fn ($query) => $query->where('id', $key)->orWhere('full_name', $key))
            ->first();

        if (! $repo) {
            $this->error('Repository not found.');

            return self::FAILURE;
        }

        try {
            $result = $dispatcher->createScan(
                $repo,
                $profile,
                null,
                includeLinkedTargets: $includeTargets,
                linkedTargetProfile: $targetProfile,
            );

            $this->info("Repo scan {$result['scan']->id} queued for {$repo->full_name} ({$profile->value}).");

            if ($includeTargets) {
                $this->info(count($result['linked_target_scans']).' linked target scan(s) queued.');
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
