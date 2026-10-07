<?php

namespace Tests\Feature;

use App\Domain\Scanning\DTO\ScannerFinding;
use App\Domain\Scanning\Services\ScanDispatcher;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\ScanProfile;
use App\Enums\ScanStatus;
use App\Enums\ScanTaskStatus;
use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Findings\Pages\ListFindings;
use App\Filament\Resources\Scans\Pages\ViewScan;
use App\Models\Asset;
use App\Models\Finding;
use App\Models\Scan;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed(DemoSeeder::class);
        $this->actingAs(User::query()->where('email', 'admin@hackly.test')->firstOrFail());
    }

    public function test_running_scan_can_be_cancelled(): void
    {
        $scan = Scan::query()->where('status', ScanStatus::Running)->firstOrFail();

        Livewire::test(ViewScan::class, ['record' => $scan->getKey()])
            ->callAction('cancelScan')
            ->assertHasNoActionErrors();

        $scan->refresh();

        $this->assertSame(ScanStatus::Cancelled, $scan->status);
        $this->assertSame(0, $scan->tasks()->whereIn('status', [ScanTaskStatus::Pending, ScanTaskStatus::Queued])->count());
        $this->assertGreaterThan(0, $scan->tasks()->where('error_message', 'Cancelled by user.')->count());

        // A late worker finishing a task must not resurrect a cancelled scan.
        $scan->refreshStatusFromTasks();
        $this->assertSame(ScanStatus::Cancelled, $scan->fresh()->status);
    }

    public function test_findings_can_be_triaged_from_the_list(): void
    {
        $finding = Finding::query()->issues()->open()->firstOrFail();

        Livewire::test(ListFindings::class)
            ->callAction(TestAction::make('triage_false_positive')->table($finding))
            ->assertHasNoActionErrors();

        $this->assertSame(FindingStatus::FalsePositive, $finding->fresh()->status);
    }

    public function test_triage_decisions_survive_a_rescan_but_fixed_findings_reopen(): void
    {
        $scan = Scan::query()->where('status', ScanStatus::Completed)->with('asset', 'tasks')->firstOrFail();
        $task = $scan->tasks->first();

        $falsePositive = new ScannerFinding(title: 'Noisy header check', severity: FindingSeverity::Medium, source: 'http', category: 'headers', fingerprint: 'fp-key');
        $regression = new ScannerFinding(title: 'Exposed .env', severity: FindingSeverity::High, source: 'http', category: 'path_discovery', fingerprint: 'regression-key');

        app(ScanDispatcher::class)->persistFindings($task, [$falsePositive, $regression]);

        Finding::query()->where('title', 'Noisy header check')->update(['status' => FindingStatus::FalsePositive]);
        Finding::query()->where('title', 'Exposed .env')->update(['status' => FindingStatus::Fixed]);

        app(ScanDispatcher::class)->persistFindings($task, [$falsePositive, $regression]);

        $this->assertSame(FindingStatus::FalsePositive, Finding::query()->where('title', 'Noisy header check')->value('status'));
        $this->assertSame(FindingStatus::Open, Finding::query()->where('title', 'Exposed .env')->value('status'));
    }

    public function test_scan_reports_can_be_exported(): void
    {
        $scan = Scan::query()->where('status', ScanStatus::Completed)->firstOrFail();

        Livewire::test(ViewScan::class, ['record' => $scan->getKey()])
            ->callAction('exportPdf')
            ->assertFileDownloaded('hackly-scan-'.substr($scan->id, 0, 8).'.pdf');

        Livewire::test(ViewScan::class, ['record' => $scan->getKey()])
            ->callAction('exportMarkdown')
            ->assertFileDownloaded('hackly-scan-'.substr($scan->id, 0, 8).'.md');
    }

    public function test_new_targets_are_normalized_and_validated(): void
    {
        Livewire::test(ListAssets::class)
            ->callAction('create', ['value' => 'https://Example.ORG/login?next=/'])
            ->assertHasNoActionErrors();

        $this->assertTrue(Asset::query()->where('value', 'example.org')->exists());
        $this->assertNull(Asset::query()->where('value', 'example.org')->value('verified_at'));

        Livewire::test(ListAssets::class)
            ->callAction('create', ['value' => '10.0.0.1'])
            ->assertHasActionErrors(['value']);
    }

    public function test_unverified_targets_cannot_be_scanned(): void
    {
        $asset = Asset::query()->whereNull('verified_at')->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);

        app(ScanDispatcher::class)->createScan($asset, ScanProfile::Quick);
    }
}
