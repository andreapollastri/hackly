<?php

namespace App\Console\Commands;

use App\Domain\RepoScanning\Services\RepoScanDispatcher;
use App\Enums\ScanProfile;
use App\Enums\ScanStatus;
use App\Models\Repository;
use Illuminate\Console\Command;
use Throwable;

class HacklyHourlyReposCommand extends Command
{
    protected $signature = 'hackly:hourly-repos
        {--profile= : Profile for repository scans (defaults to config)}';

    protected $description = 'Hourly: scan all active repositories (repo-only, no linked targets)';

    public function handle(RepoScanDispatcher $repoDispatcher): int
    {
        $profile = ScanProfile::tryFrom((string) ($this->option('profile') ?: config('hackly.repo.hourly_repo_profile', 'quick')))
            ?? ScanProfile::Quick;

        $queued = 0;
        $skipped = 0;

        $repos = Repository::query()
            ->with('credential')
            ->where('status', 'active')
            ->get();

        foreach ($repos as $repo) {
            $hasInFlight = $repo->scans()
                ->whereIn('status', [
                    ScanStatus::Pending->value,
                    ScanStatus::Running->value,
                ])
                ->exists();

            if ($hasInFlight) {
                $skipped++;
                $this->line("Repo {$repo->full_name}: skipped (scan already in progress)");

                continue;
            }

            try {
                $result = $repoDispatcher->createScan(
                    $repo,
                    $profile,
                    null,
                    includeLinkedTargets: false,
                );

                $queued++;
                $this->info("Repo {$repo->full_name} → {$result['scan']->id}");
            } catch (Throwable $e) {
                $this->error("Repo {$repo->full_name}: ".$e->getMessage());
            }
        }

        $this->newLine();
        $this->info("Hourly repos queued: {$queued} scan(s), {$skipped} skipped.");

        return self::SUCCESS;
    }
}
