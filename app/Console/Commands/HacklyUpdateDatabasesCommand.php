<?php

namespace App\Console\Commands;

use App\Domain\Scanning\Services\ScannerDatabaseUpdater;
use Illuminate\Console\Command;

class HacklyUpdateDatabasesCommand extends Command
{
    protected $signature = 'hackly:update-databases
        {--only= : Comma-separated subset: nuclei, trivy, semgrep}';

    protected $description = 'Refresh Nuclei templates, the Trivy vulnerability database, and Semgrep rules';

    public function handle(ScannerDatabaseUpdater $updater): int
    {
        $only = $this->only();

        if ($only === false) {
            return self::FAILURE;
        }

        $results = $updater->update($only);
        $this->table(['Tool', 'Status', 'Detail'], array_map(
            fn (array $row): array => [$row['tool'], $row['status'], $row['detail']],
            $results,
        ));

        $failed = collect($results)->where('status', 'failed');

        if ($failed->isNotEmpty()) {
            $this->error('Database update failed for: '.$failed->pluck('tool')->implode(', '));

            return self::FAILURE;
        }

        $this->info('Scanner databases checked. Missing tools were skipped.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null|false
     */
    private function only(): array|null|false
    {
        $raw = trim((string) $this->option('only'));

        if ($raw === '') {
            return null;
        }

        $names = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $unknown = array_diff($names, ['nuclei', 'trivy', 'semgrep']);

        if ($unknown !== []) {
            $this->error('Unknown tool: '.implode(', ', $unknown).'. Use nuclei, trivy, or semgrep.');

            return false;
        }

        return $names;
    }
}
