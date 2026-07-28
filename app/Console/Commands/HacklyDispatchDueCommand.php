<?php

namespace App\Console\Commands;

use App\Domain\Scanning\Services\ScanDispatcher;
use Illuminate\Console\Command;

class HacklyDispatchDueCommand extends Command
{
    protected $signature = 'hackly:dispatch-due {--limit=20 : Max pending tasks to dispatch}';

    protected $description = 'Dispatch any leftover pending scan tasks (normally unnecessary — scans dispatch on create)';

    public function handle(ScanDispatcher $dispatcher): int
    {
        $count = $dispatcher->dispatchDueTasks((int) $this->option('limit'));
        $this->info("Dispatched {$count} task(s).");

        return self::SUCCESS;
    }
}
