<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Assets\Pages\ViewAsset;
use App\Filament\Resources\Assets\RelationManagers\FindingsRelationManager as AssetFindings;
use App\Filament\Resources\Assets\RelationManagers\RepositoriesRelationManager as AssetRepositories;
use App\Filament\Resources\Assets\RelationManagers\ScansRelationManager as AssetScans;
use App\Filament\Resources\Findings\Pages\ListFindings;
use App\Filament\Resources\RepoScans\Pages\ViewRepoScan;
use App\Filament\Resources\RepoScans\RelationManagers\FindingsRelationManager as RepoScanFindings;
use App\Filament\Resources\Repositories\Pages\ViewRepository;
use App\Filament\Resources\Repositories\RelationManagers\AssetsRelationManager as RepositoryTargets;
use App\Filament\Resources\Repositories\RelationManagers\FindingsRelationManager as RepositoryFindings;
use App\Filament\Resources\Repositories\RelationManagers\ScansRelationManager as RepositoryScans;
use App\Filament\Resources\Scans\Pages\ViewScan;
use App\Filament\Resources\Scans\RelationManagers\FindingsRelationManager as ScanFindings;
use App\Filament\Resources\Scans\Widgets\RepoScansTableWidget;
use App\Filament\Widgets\FindingsTrendChart;
use App\Filament\Widgets\GettingStarted;
use App\Filament\Widgets\IssuesBySourceChart;
use App\Filament\Widgets\PostureStats;
use App\Filament\Widgets\PriorityFindings;
use App\Filament\Widgets\RecentRepoScans;
use App\Filament\Widgets\RecentTargetScans;
use App\Models\Asset;
use App\Models\Finding;
use App\Models\RepoScan;
use App\Models\Repository;
use App\Models\Scan;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Renders every page, widget and relation manager of the panel against the demo workspace.
 */
class PanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
        $this->actingAs(User::query()->where('email', 'admin@hackly.test')->firstOrFail());
    }

    public function test_guests_are_redirected_to_login(): void
    {
        auth()->logout();

        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('authorized to assess');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function staticPages(): array
    {
        return [
            'dashboard' => ['/'],
            'targets' => ['/targets'],
            'repositories' => ['/repositories'],
            'scans' => ['/scans'],
            'repository scans tab' => ['/scans?scope=repositories::tab'],
            'findings' => ['/findings'],
            'findings (all)' => ['/findings?tab=all'],
            'github tokens' => ['/github-tokens'],
            'scanner health' => ['/scanner-health'],
            'profile' => ['/profile'],
        ];
    }

    #[DataProvider('staticPages')]
    public function test_static_pages_render(string $url): void
    {
        $this->get($url)->assertOk();
    }

    public function test_record_pages_render(): void
    {
        $asset = Asset::query()->where('value', 'acme.dev')->firstOrFail();
        $unverified = Asset::query()->whereNull('verified_at')->firstOrFail();
        $repository = Repository::query()->where('full_name', 'acme/storefront')->firstOrFail();
        $scan = Scan::query()->where('status', 'completed')->firstOrFail();
        $running = Scan::query()->where('status', 'running')->firstOrFail();
        $repoScan = RepoScan::query()->where('status', 'completed')->firstOrFail();
        $finding = Finding::query()->issues()->firstOrFail();

        $this->get("/targets/{$asset->id}")->assertOk()->assertSee('acme.dev');
        $this->get("/targets/{$unverified->id}")->assertOk()->assertSee('Verify ownership to unlock scanning')->assertSee('hackly-verify=');
        $this->get("/targets/{$asset->id}/edit")->assertOk();
        $this->get("/repositories/{$repository->id}")->assertOk()->assertSee('acme/storefront');
        $this->get("/repositories/{$repository->id}/edit")->assertOk();
        $this->get("/scans/{$scan->id}")->assertOk()->assertSee('tasks finished');
        $this->get("/scans/{$running->id}")->assertOk()->assertSee('Running');
        $this->get("/repo-scans/{$repoScan->id}")->assertOk();
        $this->get("/findings/{$finding->id}")->assertOk();
    }

    public function test_widgets_render(): void
    {
        foreach ([GettingStarted::class, PostureStats::class, FindingsTrendChart::class, IssuesBySourceChart::class, PriorityFindings::class, RecentTargetScans::class, RecentRepoScans::class, RepoScansTableWidget::class] as $widget) {
            Livewire::test($widget)->assertOk();
        }

        Livewire::test(Dashboard::class)->assertOk();
    }

    public function test_relation_managers_render(): void
    {
        $asset = Asset::query()->where('value', 'acme.dev')->firstOrFail();
        $repository = Repository::query()->where('full_name', 'acme/billing-api')->firstOrFail();
        $scan = Scan::query()->where('status', 'completed')->firstOrFail();
        $repoScan = RepoScan::query()->where('status', 'completed')->firstOrFail();

        foreach ([AssetFindings::class, AssetScans::class, AssetRepositories::class] as $manager) {
            Livewire::test($manager, ['ownerRecord' => $asset, 'pageClass' => ViewAsset::class])->assertOk();
        }

        foreach ([RepositoryFindings::class, RepositoryScans::class, RepositoryTargets::class] as $manager) {
            Livewire::test($manager, ['ownerRecord' => $repository, 'pageClass' => ViewRepository::class])->assertOk();
        }

        Livewire::test(ScanFindings::class, ['ownerRecord' => $scan, 'pageClass' => ViewScan::class])->assertOk();
        Livewire::test(RepoScanFindings::class, ['ownerRecord' => $repoScan, 'pageClass' => ViewRepoScan::class])->assertOk();
    }

    public function test_findings_list_defaults_to_open_issues_only(): void
    {
        Livewire::test(ListFindings::class)
            ->loadTable()
            ->assertOk()
            ->assertCanNotSeeTableRecords(Finding::query()->where('category', 'passed')->get())
            ->assertCanSeeTableRecords(Finding::query()->issues()->open()->where('severity', 'high')->get());
    }
}
