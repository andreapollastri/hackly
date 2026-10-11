<?php

namespace Tests\Feature;

use App\Mail\WeeklyDigestMail;
use App\Models\Finding;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FindingShareAndDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);
    }

    public function test_false_positive_requires_a_note_and_share_links_are_unguessable(): void
    {
        $user = User::query()->where('email', 'admin@hackly.test')->firstOrFail();
        $finding = Finding::query()->issues()->open()->firstOrFail();
        $token = $user->createToken('tests', ['*'])->plainTextToken;

        $this->getJson('/api/v1/findings/'.$finding->id)->assertUnauthorized();

        $this->withToken($token)
            ->patchJson('/api/v1/findings/'.$finding->id, ['status' => 'false_positive'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note');

        $note = 'Scanner matched a fixture hostname that is not deployed.';

        $this->withToken($token)
            ->patchJson('/api/v1/findings/'.$finding->id, [
                'status' => 'false_positive',
                'note' => $note,
            ])
            ->assertOk()
            ->assertJsonPath('data.false_positive_note', $note)
            ->assertJsonPath('data.status', 'false_positive');

        $this->withToken($token)
            ->get('/api/v1/findings/'.$finding->id.'/markdown')
            ->assertOk()
            ->assertSee($note, false)
            ->assertSee($finding->title, false);

        $this->withToken($token)
            ->get('/api/v1/findings/'.$finding->id.'/prompt')
            ->assertOk()
            ->assertSee('How to fix it', false);

        $share = $this->withToken($token)
            ->postJson('/api/v1/findings/'.$finding->id.'/share')
            ->assertCreated()
            ->json('data.share_url');

        $this->assertNotNull($share);
        $hash = $finding->fresh()->publish_hash;
        $this->assertSame(40, strlen((string) $hash));

        $this->get($share)
            ->assertOk()
            ->assertSee($finding->title)
            ->assertSee($note)
            ->assertSee('Copy AI prompt')
            ->assertSee('noindex', false);

        $this->get($share.'?format=markdown')
            ->assertOk()
            ->assertSee($note, false);

        $this->get('/f/'.str_repeat('a', 40))->assertNotFound();

        $this->withToken($token)
            ->deleteJson('/api/v1/findings/'.$finding->id.'/share')
            ->assertNoContent();

        $this->get($share)->assertNotFound();

        $this->app['auth']->forgetGuards();

        $read = $user->createToken('read', ['read'])->plainTextToken;

        $this->withToken($read)
            ->postJson('/api/v1/findings/'.$finding->id.'/share')
            ->assertForbidden();
    }

    public function test_sunday_digest_covers_findings_and_situations(): void
    {
        Mail::fake();

        $this->artisan('hackly:digest')->assertSuccessful();

        Mail::assertSent(WeeklyDigestMail::class, function (WeeklyDigestMail $mail): bool {
            $text = $mail->report['text'];

            return str_contains($mail->report['subject'], 'weekly digest')
                && str_contains($text, 'Open now')
                && str_contains($text, 'Situations')
                && str_contains($text, 'Unverified targets');
        });

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => User::query()->where('email', 'admin@hackly.test')->value('id'),
        ]);

        $user = User::query()->where('email', 'admin@hackly.test')->firstOrFail();
        $token = $user->createToken('tests', ['read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/digest')
            ->assertOk()
            ->assertJsonStructure(['data' => ['posture', 'open', 'situations', 'text']]);
    }

    public function test_api_reference_is_published(): void
    {
        $this->get('/api/docs')->assertOk();
        $this->get('/api/openapi.yaml')->assertOk();

        $spec = (string) file_get_contents(base_path('docs/openapi.yaml'));
        $this->assertStringContainsString('/v1/digest', $spec);
        $this->assertStringContainsString('/v1/findings/{finding}/share', $spec);
    }
}
