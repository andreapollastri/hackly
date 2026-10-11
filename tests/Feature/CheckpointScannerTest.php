<?php

namespace Tests\Feature;

use App\Domain\RepoScanning\DTO\RawFinding;
use App\Domain\RepoScanning\Scanners\CheckpointScanner;
use App\Enums\FindingSeverity;
use App\Enums\ScanProfile;
use Checkpoint\Scanner;
use Tests\TestCase;

class CheckpointScannerTest extends TestCase
{
    public function test_every_checkpoint_check_runs_against_the_repository(): void
    {
        $workspace = sys_get_temp_dir().'/hackly-checkpoint-'.bin2hex(random_bytes(4));
        mkdir($workspace.'/app/Models', 0755, true);
        mkdir($workspace.'/resources/views', 0755, true);

        file_put_contents($workspace.'/.gitignore', implode("\n", [
            '.env',
            '*.key',
            '*.pem',
            'storage/logs',
            '.env.backup',
            '.env.production',
            'auth.json',
            'vendor/',
        ]));

        file_put_contents($workspace.'/app/Models/User.php', <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $guarded = [];
}
PHP);

        file_put_contents($workspace.'/resources/views/form.blade.php', <<<'HTML'
<form method="POST" action="/account">
    <input name="email">
</form>
HTML);

        file_put_contents($workspace.'/app/Xml.php', <<<'PHP'
<?php

$dom = new DOMDocument();
$dom->loadXML($xml, LIBXML_NOENT);
PHP);

        try {
            $registered = array_map(
                fn ($check) => $check->name(),
                Scanner::withDefaultChecks($workspace)->checks(),
            );

            foreach (CheckpointScanner::CHECKS as $name) {
                $this->assertContains($name, $registered);
            }

            $this->assertStringContainsString('30 Checkpoint checks', ScanProfile::Quick->repositoryDescription());
            $this->assertContains('checkpoint', config('hackly.repo.profiles.quick'));
            $this->assertContains('checkpoint', config('hackly.repo.profiles.standard'));
            $this->assertContains('checkpoint', config('hackly.repo.profiles.deep'));

            $this->assertGreaterThanOrEqual(30, count($registered));

            $findings = (new CheckpointScanner)->findings($workspace);
            $titles = array_map(fn ($finding) => $finding->title, $findings);
            $blob = implode("\n", $titles);

            $mass = $this->only($findings, 'guarded');
            $csrf = $this->only($findings, '@csrf');
            $xxe = $this->only($findings, 'LIBXML_NOENT');

            $this->assertCount(1, $mass);
            $this->assertSame(FindingSeverity::Medium, $mass[0]->severity);
            $this->assertSame('app/Models/User.php', $mass[0]->file);
            $this->assertSame('laravel', $mass[0]->category);

            $this->assertCount(1, $csrf);
            $this->assertSame(FindingSeverity::High, $csrf[0]->severity);
            $this->assertSame('resources/views/form.blade.php', $csrf[0]->file);

            $this->assertCount(1, $xxe);
            $this->assertSame(FindingSeverity::High, $xxe[0]->severity);
            $this->assertSame('sast', $xxe[0]->category);
            $this->assertSame('app/Xml.php', $xxe[0]->file);
            $this->assertSame(4, $xxe[0]->line);

            $this->assertStringNotContainsString('APP_DEBUG', $blob);
            $this->assertStringNotContainsString('APP_URL', $blob);
            $this->assertStringNotContainsString('.env file not found', $blob);

            foreach ($findings as $finding) {
                $this->assertSame('checkpoint', $finding->source);
                $this->assertStringStartsWith('checkpoint|', $finding->resolvedDedupeKey());
            }
        } finally {
            $this->deleteTree($workspace);
        }
    }

    /**
     * @param  list<RawFinding>  $findings
     * @return list<RawFinding>
     */
    private function only(array $findings, string $needle): array
    {
        return array_values(array_filter(
            $findings,
            fn ($finding) => str_contains($finding->title, $needle),
        ));
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
