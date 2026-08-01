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
        $hints = [];

        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $user = (function_exists('posix_getpwuid') && is_int($uid))
            ? (posix_getpwuid($uid)['name'] ?? get_current_user())
            : get_current_user();
        $this->line("PHP user: {$user} (uid {$uid})");
        $this->line('HOME: '.(getenv('HOME') ?: '—'));
        $this->newLine();

        foreach ($runner->checkConfiguredBinaries() as $name => $info) {
            $rows[] = [
                $name,
                $info['path'],
                $info['resolved'] ?? '—',
                $info['available'] ? 'yes' : 'NO',
            ];

            if (! $info['available']) {
                $missing++;
                if (! empty($info['hint'])) {
                    $hints[] = "{$name}: {$info['hint']}";
                }
            }
        }

        $this->table(['Binary', 'Configured', 'Resolved', 'Available'], $rows);

        if ($missing > 0) {
            $this->warn("{$missing} binary/binaries missing. Related scan tasks will fail or soft-fallback.");

            foreach ($hints as $hint) {
                $this->error($hint);
            }

            if ($hints === []) {
                $this->line('Install system-wide (readable by queue user):');
                $this->line('  sudo bash scripts/install-repo-scanners.sh');
            }

            return self::FAILURE;
        }

        $this->info('All configured binaries are available.');

        return self::SUCCESS;
    }
}
