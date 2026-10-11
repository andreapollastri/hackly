<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\RepoScanning\Services\GithubClient;
use App\Filament\Resources\GithubCredentials\GithubCredentialResource as CredentialForm;
use App\Http\Resources\GithubCredentialResource;
use App\Models\GithubCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GithubCredentialController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $credentials = GithubCredential::query()
            ->withCount('repositories')
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return GithubCredentialResource::collection($credentials)->response();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'token' => ['required', 'string', 'min:8'],
        ]);

        $credential = CredentialForm::createCredential($data);

        return (new GithubCredentialResource($credential->loadCount('repositories')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(GithubCredential $credential): GithubCredentialResource
    {
        $credential->loadCount('repositories');

        return new GithubCredentialResource($credential);
    }

    public function update(Request $request, GithubCredential $credential): GithubCredentialResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'token' => ['sometimes', 'string', 'min:8'],
        ]);

        if (array_key_exists('name', $data)) {
            $credential->name = $data['name'];
        }

        if (filled($data['token'] ?? null)) {
            $credential->token = $data['token'];
            $credential->token_hint = GithubCredential::hintFromToken($data['token']);
            $credential->validation_status = 'unknown';
        }

        $credential->save();

        if (filled($data['token'] ?? null)) {
            try {
                app(GithubClient::class)->validateToken($credential);
            } catch (\Throwable) {
                $credential->update(['validation_status' => 'invalid', 'last_validated_at' => now()]);
            }
        }

        return new GithubCredentialResource($credential->fresh()->loadCount('repositories'));
    }

    public function destroy(GithubCredential $credential): JsonResponse
    {
        if ($credential->repositories()->exists()) {
            $this->fail('This token is still used by repositories. Move or delete those repositories first.', 409);
        }

        $credential->delete();

        return response()->json(null, 204);
    }

    public function validateToken(GithubCredential $credential): GithubCredentialResource|JsonResponse
    {
        $this->domain(fn () => app(GithubClient::class)->validateToken($credential));

        return new GithubCredentialResource($credential->fresh());
    }
}
