<?php

namespace App\Console\Commands;

use App\Domain\RepoScanning\Services\RepoScanDispatcher;
use App\Domain\Scanning\Services\ScanDispatcher;
use App\Enums\AssetStatus;
use App\Enums\ScanProfile;
use App\Models\Asset;
use App\Models\Repository;
use Illuminate\Console\Command;
use Throwable;

class HacklyNightlyCommand extends Command
{
    protected $signature = 'hackly:nightly
        {--repo-profile= : Profile for repository scans (defaults to config)}
        {--target-profile= : Profile for target DAST scans (defaults to config / deep)}';

    protected $description = 'Nightly: scan all nightly repos (+ deep linked targets) and deep-scan targets without a repo';

    public function handle(RepoScanDispatcher $repoDispatcher, ScanDispatcher $scanDispatcher): int
    {
        $repoProfile = ScanProfile::tryFrom((string) ($this->option('repo-profile') ?: config('hackly.repo.nightly_repo_profile', 'standard')))
            ?? ScanProfile::Standard;
        $targetProfile = ScanProfile::tryFrom((string) ($this->option('target-profile') ?: config('hackly.repo.nightly_target_profile', 'deep')))
            ?? ScanProfile::Deep;

        $coveredAssetIds = [];
        $repoCount = 0;
        $linkedTargetCount = 0;
        $standaloneTargetCount = 0;

        $repos = Repository::query()
            ->with(['credential', 'assets'])
            ->where('nightly_enabled', true)
            ->where('status', 'active')
            ->get();

        foreach ($repos as $repo) {
            try {
                $result = $repoDispatcher->createScan(
                    $repo,
                    $repoProfile,
                    null,
                    includeLinkedTargets: true,
                    linkedTargetProfile: $targetProfile,
                    ignoreTargetCooldown: true,
                    skipAssetIds: $coveredAssetIds,
                );

                $repoCount++;
                $linkedTargetCount += count($result['linked_target_scans']);

                foreach ($repo->assets as $asset) {
                    $coveredAssetIds[] = $asset->id;
                }

                $this->info("Repo {$repo->full_name} → {$result['scan']->id} (+ ".count($result['linked_target_scans']).' linked target scan(s))');
            } catch (Throwable $e) {
                $this->error("Repo {$repo->full_name}: ".$e->getMessage());
            }
        }

        $coveredAssetIds = array_values(array_unique($coveredAssetIds));

        $standaloneTargets = Asset::query()
            ->where('status', AssetStatus::Active->value)
            ->whereNotNull('verified_at')
            ->whereDoesntHave('repositories')
            ->get();

        foreach ($standaloneTargets as $asset) {
            try {
                $result = $scanDispatcher->createScan(
                    $asset,
                    $targetProfile,
                    null,
                    includeLinkedRepos: false,
                    ignoreCooldown: true,
                );
                $standaloneTargetCount++;
                $this->info("Target {$asset->value} → {$result['scan']->id}");
            } catch (Throwable $e) {
                $this->error("Target {$asset->value}: ".$e->getMessage());
            }
        }

        $this->newLine();
        $this->info("Nightly queued: {$repoCount} repo scan(s), {$linkedTargetCount} linked-target scan(s), {$standaloneTargetCount} standalone-target scan(s).");

        return self::SUCCESS;
    }
}
