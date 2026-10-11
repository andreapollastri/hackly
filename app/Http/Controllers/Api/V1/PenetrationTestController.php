<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Pentest\PentestAuthorizer;
use App\Domain\Pentest\PentestDispatcher;
use App\Domain\Pentest\PentestScope;
use App\Enums\AssetStatus;
use App\Enums\PentestTechnology;
use App\Http\Resources\PenetrationTestResource;
use App\Http\Resources\ScanResource;
use App\Models\Asset;
use App\Models\PenetrationTest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PenetrationTestController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $tests = PenetrationTest::query()
            ->with('asset:id,value')
            ->latest()
            ->paginate($this->perPage($request));

        return PenetrationTestResource::collection($tests)->response();
    }

    public function show(PenetrationTest $pentest): PenetrationTestResource
    {
        $pentest->load('asset:id,value');

        return new PenetrationTestResource($pentest);
    }

    public function store(Request $request): JsonResponse
    {
        $pentest = PenetrationTest::query()->create([
            ...$this->validated($request),
            'created_by' => $request->user()?->id,
        ]);

        return (new PenetrationTestResource($pentest->load('asset')))->response()->setStatusCode(201);
    }

    public function update(Request $request, PenetrationTest $pentest): PenetrationTestResource
    {
        $pentest->update($this->validated($request, $pentest));

        return new PenetrationTestResource($pentest->fresh()->load('asset'));
    }

    public function destroy(PenetrationTest $pentest): JsonResponse
    {
        $pentest->delete();

        return response()->json(null, 204);
    }

    public function issue(PenetrationTest $pentest, PentestAuthorizer $authorizer): PenetrationTestResource
    {
        $this->domain(fn () => $authorizer->issue($pentest));

        return new PenetrationTestResource($pentest->fresh()->load('asset'));
    }

    public function check(PenetrationTest $pentest, PentestAuthorizer $authorizer): PenetrationTestResource
    {
        $this->domain(fn () => $authorizer->check($pentest));

        return new PenetrationTestResource($pentest->fresh()->load('asset'));
    }

    public function run(Request $request, PenetrationTest $pentest, PentestDispatcher $dispatcher): JsonResponse
    {
        $scan = $this->domain(fn () => $dispatcher->start($pentest, $request->user()));

        return response()->json([
            'data' => (new ScanResource($scan->load(['asset', 'tasks', 'requester'])))->resolve($request),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?PenetrationTest $existing = null): array
    {
        $data = $request->validate([
            'asset_id' => [$existing ? 'sometimes' : 'required', 'uuid', 'exists:assets,id'],
            'label' => [$existing ? 'sometimes' : 'required', 'string', 'max:120'],
            'technology' => [$existing ? 'sometimes' : 'required', Rule::enum(PentestTechnology::class)],
            'technology_notes' => ['nullable', 'string', 'max:2000'],
            'swagger_url' => ['nullable', 'string', 'max:500'],
            'login_urls' => ['nullable', 'array', 'max:15'],
            'login_urls.*' => ['string', 'max:500'],
            'extra_urls' => ['nullable', 'array', 'max:15'],
            'extra_urls.*' => ['string', 'max:500'],
            'ports' => ['nullable', 'array', 'max:40'],
            'ports.*' => ['integer', 'between:1,65535'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'authentication_notes' => ['nullable', 'string', 'max:2000'],
            'out_of_scope' => ['nullable', 'string', 'max:2000'],
            'test_username' => ['nullable', 'string', 'max:200'],
            'test_password' => ['nullable', 'string', 'max:200'],
        ]);

        $assetId = $data['asset_id'] ?? $existing?->asset_id;
        $asset = Asset::query()->find($assetId);

        if (! $asset || ! $asset->isVerified() || $asset->status !== AssetStatus::Active) {
            throw ValidationException::withMessages([
                'asset_id' => 'Choose an active target that has already passed DNS ownership verification.',
            ]);
        }

        foreach (['swagger_url' => $data['swagger_url'] ?? null] as $field => $url) {
            if (filled($url) && PentestScope::normalizeUrl((string) $url, $asset->value) === null) {
                throw ValidationException::withMessages([
                    $field => "The URL must stay on {$asset->value}.",
                ]);
            }
        }

        foreach (['login_urls', 'extra_urls'] as $field) {
            foreach ($data[$field] ?? [] as $url) {
                if (PentestScope::normalizeUrl((string) $url, $asset->value) === null) {
                    throw ValidationException::withMessages([
                        $field => "{$url} is outside {$asset->value}.",
                    ]);
                }
            }
        }

        if (array_key_exists('test_password', $data) && blank($data['test_password'])) {
            unset($data['test_password']);
        }

        return $data;
    }
}
