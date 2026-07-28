<?php

namespace App\Console\Commands;

use App\Domain\Scanning\Services\BinaryRunner;
use Illuminate\Console\Command;

class HacklyCheckBinariesCommand extends Command
{
    protected $signature = 'hackly:check-binaries';

    protected $description = 'Check availability of required scanner binaries';

    public function handle(BinaryRunner $runner): int
    {
        $rows = [];
        $missing = 0;

        foreach ($runner->checkConfiguredBinaries() as $name => $info) {
            $rows[] = [
                $name,
                $info['path'],
                $info['available'] ? 'yes' : 'NO',
            ];

            if (! $info['available']) {
                $missing++;
            }
        }

        $this->table(['Binary', 'Path', 'Available'], $rows);

        if ($missing > 0) {
            $this->warn("{$missing} binary/binaries missing. Related scan tasks will fail or soft-fallback.");

            return self::FAILURE;
        }

        $this->info('All configured binaries are available.');

        return self::SUCCESS;
    }
}
