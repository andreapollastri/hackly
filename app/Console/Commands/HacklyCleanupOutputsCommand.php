<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class HacklyCleanupOutputsCommand extends Command
{
    protected $signature = 'hackly:cleanup-outputs {--days=14 : Delete raw outputs older than N days}';

    protected $description = 'Cleanup old raw scanner output files';

    public function handle(): int
    {
        $root = (string) config('hackly.storage_path');
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days)->getTimestamp();
        $deleted = 0;

        if (! is_dir($root)) {
            $this->info('No scan output directory yet.');

            return self::SUCCESS;
        }

        foreach (File::allFiles($root) as $file) {
            if ($file->getMTime() < $cutoff) {
                File::delete($file->getPathname());
                $deleted++;
            }
        }

        $this->info("Deleted {$deleted} old output file(s).");

        return self::SUCCESS;
    }
}
