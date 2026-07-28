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
        {--profile=standard : quick|standard|deep}';

    protected $description = 'Create a scan for a verified, authorized target and dispatch jobs immediately';

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
            $scan = $dispatcher->createScan($asset, $profile, $this->laravel->make('auth')->user());
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $queued = $scan->tasks()->where('status', 'queued')->count();
        $this->info("Scan {$scan->id} started — {$queued} job(s) dispatched to the queue.");
        $this->comment('Keep `php artisan queue:work` running to process tasks.');

        return self::SUCCESS;
    }
}
