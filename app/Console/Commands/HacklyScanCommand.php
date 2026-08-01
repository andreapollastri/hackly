<?php

namespace App\Console\Commands;

use App\Domain\Scanning\Services\ScanDispatcher;
use App\Enums\ScanProfile;
use App\Models\Asset;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class HacklyScanCommand extends Command
{
    protected $signature = 'hackly:scan
        {asset : Target UUID or domain/IP value}
        {--profile=standard : quick|standard|deep}
        {--include-repos : Also scan all linked GitHub repositories}
        {--repo-profile= : Profile for linked repos (defaults to --profile)}';

    protected $description = 'Create a scan for a verified target (optionally including linked repos)';

    public function handle(ScanDispatcher $dispatcher): int
    {
        $key = (string) $this->argument('asset');
        $asset = Asset::query()
            ->when(
                Str::isUuid($key),
                fn ($q) => $q->where('id', $key),
                fn ($q) => $q->where('value', $key),
            )
            ->first();

        if (! $asset) {
            $this->error("Target [{$key}] not found.");

            return self::FAILURE;
        }

        try {
            $profile = ScanProfile::from((string) $this->option('profile'));
            $repoProfileOption = $this->option('repo-profile');
            $repoProfile = filled($repoProfileOption)
                ? (ScanProfile::tryFrom((string) $repoProfileOption) ?? $profile)
                : $profile;

            $result = $dispatcher->createScan(
                $asset,
                $profile,
                $this->laravel->make('auth')->user(),
                includeLinkedRepos: (bool) $this->option('include-repos'),
                linkedRepoProfile: $repoProfile,
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $scan = $result['scan'];
        $queued = $scan->tasks()->where('status', 'queued')->count();
        $this->info("Scan {$scan->id} started — {$queued} job(s) dispatched to the queue.");

        if ($result['linked_repo_scans'] !== []) {
            $this->info(count($result['linked_repo_scans']).' linked repo scan(s) queued.');
        }

        $this->comment('Keep `php artisan queue:work` running to process tasks.');

        return self::SUCCESS;
    }
}
