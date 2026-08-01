<?php

namespace App\Domain\RepoScanning\Services;

use App\Models\GithubCredential;
use App\Models\Repository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GithubClient
{
    public function http(GithubCredential $credential): PendingRequest
    {
        return Http::baseUrl('https://api.github.com')
            ->withToken($credential->token)
            ->accept('application/vnd.github+json')
            ->withHeaders([
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'Hackly-RepoScanner',
            ])
            ->timeout(30);
    }

    /**
     * @return array{login: string, scopes: list<string>}
     */
    public function validateToken(GithubCredential $credential): array
    {
        $response = $this->http($credential)->get('/user');

        if (! $response->successful()) {
            throw new RuntimeException('GitHub token validation failed (HTTP '.$response->status().'). Check classic scopes or fine-grained permissions.');
        }

        $scopesHeader = (string) $response->header('X-OAuth-Scopes');
        $scopes = array_values(array_filter(array_map('trim', explode(',', $scopesHeader))));

        $credential->update([
            'last_validated_at' => now(),
            'validation_status' => 'valid',
            'meta' => array_merge($credential->meta ?? [], [
                'login' => $response->json('login'),
                'scopes' => $scopes,
            ]),
        ]);

        return [
            'login' => (string) $response->json('login'),
            'scopes' => $scopes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchRepository(GithubCredential $credential, string $owner, string $name): array
    {
        $response = $this->http($credential)->get("/repos/{$owner}/{$name}");

        if ($response->status() === 404) {
            throw new RuntimeException("Repository {$owner}/{$name} not found or token lacks access.");
        }

        if (! $response->successful()) {
            throw new RuntimeException('GitHub API error while fetching repository (HTTP '.$response->status().').');
        }

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }

    public function authenticatedCloneUrl(Repository $repository): string
    {
        $token = $repository->credential?->token;

        if (! filled($token)) {
            throw new RuntimeException('GitHub credential token is missing.');
        }

        return 'https://x-access-token:'.rawurlencode($token).'@github.com/'.$repository->full_name.'.git';
    }
}
