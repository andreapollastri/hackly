<?php

namespace Tests\Feature;

use App\Domain\Compliance\ComplianceSubject;
use App\Domain\Compliance\PostureScore;
use App\Domain\Scanning\Scanners\NucleiPentestScanner;
use App\Domain\Scanning\Scanners\PathDiscoveryScanner;
use App\Domain\Scanning\Support\AppSurfaceCatalog;
use App\Enums\AssetStatus;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Filament\Pages\Compliance;
use App\Models\Asset;
use App\Models\Finding;
use App\Models\GithubCredential;
use App\Models\Repository;
use App\Models\Scan;
use App\Models\ScanTask;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class ComplianceSubjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_filter_lists_a_target_a_repository_or_the_pair(): void
    {
        [$shop, $billing, $linked, $orphan] = $this->workspace();

        $options = ComplianceSubject::options();

        $this->assertSame($billing->value, $options['target:'.$billing->id]);
        $this->assertSame($orphan->full_name, $options['repository:'.$orphan->id]);
        $this->assertSame($shop->value.' + '.$linked->full_name, $options['pair:'.$shop->id.':'.$linked->id]);
        $this->assertArrayNotHasKey('target:'.$shop->id, $options);
        $this->assertArrayNotHasKey('repository:'.$linked->id, $options);

        $pair = ComplianceSubject::fromKey('pair:'.$shop->id.':'.$linked->id);
        $scores = app(PostureScore::class);

        $this->assertSame(1, $scores->snapshot(subject: $pair)['open']['high']);
        $this->assertSame(1, $scores->snapshot(subject: $pair)['open']['medium']);
        $this->assertSame(1, $scores->snapshot(subject: ComplianceSubject::fromKey('target:'.$billing->id))['open']['high']);
        $this->assertSame(0, $scores->snapshot(subject: ComplianceSubject::fromKey('target:'.$billing->id))['open']['medium']);
        $this->assertSame(2, $scores->snapshot()['open']['high']);
        $this->assertNull($scores->snapshot()['subject']);
        $this->assertSame($shop->value.' + '.$linked->full_name, $scores->snapshot(subject: $pair)['subject']['label']);

        $this->expectException(ModelNotFoundException::class);
        ComplianceSubject::fromKey('pair:'.$billing->id.':'.$linked->id);
    }

    public function test_reports_and_the_page_follow_the_selected_pair(): void
    {
        [$shop, $billing, $linked] = $this->workspace();
        $user = User::factory()->create();
        $this->actingAs($user);
        $key = 'pair:'.$shop->id.':'.$linked->id;

        Livewire::test(Compliance::class)
            ->assertSee('All')
            ->assertSee($shop->value.' + '.$linked->full_name)
            ->assertSee($billing->value)
            ->set('subject', $key)
            ->assertSee($shop->value.' + '.$linked->full_name);

        $response = $this->get(route('compliance.posture', ['format' => 'markdown', 'subject' => $key]));
        $response->assertOk();
        $body = $response->streamedContent();

        $this->assertStringContainsString($shop->value.' + '.$linked->full_name, $body);
        $this->assertStringContainsString('Shop secret', $body);
        $this->assertStringNotContainsString('Billing secret', $body);

        $this->get(route('compliance.posture', ['format' => 'markdown', 'subject' => 'nope']))
            ->assertNotFound();
    }

    public function test_linked_repository_routes_are_reused_by_dast(): void
    {
        $directory = sys_get_temp_dir().'/hackly-surface-'.bin2hex(random_bytes(4));
        mkdir($directory.'/routes', 0777, true);
        mkdir($directory.'/app/billing', 0777, true);
        file_put_contents($directory.'/routes/api.php', "<?php\nRoute::get('/billing', fn () => 'ok');\nRoute::get('/users/{id}', fn () => 'no');\n");
        file_put_contents($directory.'/app/billing/page.tsx', 'export default function Page() { return null }');
        file_put_contents($directory.'/artisan', '');

        $paths = AppSurfaceCatalog::extract($directory);
        $this->assertContains('/billing', $paths);
        $this->assertNotContains('/users/{id}', $paths);
        $this->assertSame('laravel', AppSurfaceCatalog::technology($directory));

        [$shop, , $linked] = $this->workspace();
        $linked->forceFill(['meta' => ['surface_paths' => ['/billing', '/.env']]])->save();

        $discovered = (new PathDiscoveryScanner)->discoveryPaths($shop);
        $this->assertSame('/billing', $discovered[0]);

        $scan = new Scan;
        $scan->setRelation('penetrationTest', null);
        $task = new ScanTask;
        $task->setRelation('scan', $scan);
        $method = new ReflectionMethod(NucleiPentestScanner::class, 'targetArguments');
        $arguments = $method->invoke(new NucleiPentestScanner, $shop, $task);

        $this->assertContains('https://'.$shop->value.'/billing', $arguments);

        unlink($directory.'/routes/api.php');
        unlink($directory.'/app/billing/page.tsx');
        unlink($directory.'/artisan');
        rmdir($directory.'/routes');
        rmdir($directory.'/app/billing');
        rmdir($directory.'/app');
        rmdir($directory);
    }

    /**
     * @return array{0: Asset, 1: Asset, 2: Repository, 3: Repository}
     */
    private function workspace(): array
    {
        $credential = GithubCredential::query()->create([
            'name' => 'bot',
            'token' => 'token',
        ]);

        $shop = Asset::query()->create([
            'value' => 'shop.example.com',
            'status' => AssetStatus::Active,
            'verified_at' => now(),
        ]);
        $billing = Asset::query()->create([
            'value' => 'billing.example.com',
            'status' => AssetStatus::Active,
            'verified_at' => now(),
        ]);
        $linked = Repository::query()->create([
            'github_credential_id' => $credential->id,
            'owner' => 'acme',
            'name' => 'shop',
            'full_name' => 'acme/shop',
        ]);
        $orphan = Repository::query()->create([
            'github_credential_id' => $credential->id,
            'owner' => 'acme',
            'name' => 'notes',
            'full_name' => 'acme/notes',
        ]);
        $shop->repositories()->attach($linked);

        Finding::query()->create([
            'asset_id' => $shop->id,
            'severity' => FindingSeverity::High,
            'title' => 'Shop secret',
            'source' => 'http',
            'category' => 'exposure',
            'status' => FindingStatus::Open,
            'fingerprint' => 'shop-secret',
        ]);
        Finding::query()->create([
            'repository_id' => $linked->id,
            'severity' => FindingSeverity::Medium,
            'title' => 'Repo issue',
            'source' => 'semgrep',
            'category' => 'sast',
            'status' => FindingStatus::Open,
            'fingerprint' => 'repo-issue',
        ]);
        Finding::query()->create([
            'asset_id' => $billing->id,
            'severity' => FindingSeverity::High,
            'title' => 'Billing secret',
            'source' => 'http',
            'category' => 'exposure',
            'status' => FindingStatus::Open,
            'fingerprint' => 'billing-secret',
        ]);

        return [$shop, $billing, $linked, $orphan];
    }
}
