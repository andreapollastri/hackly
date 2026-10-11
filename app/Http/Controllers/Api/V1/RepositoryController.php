<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\RepoScanning\Services\GithubClient;
use App\Domain\RepoScanning\Services\RepoScanDispatcher;
use App\Enums\ScanProfile;
use App\Filament\Resources\Repositories\RepositoryResource as RepositoryForm;
use App\Http\Resources\RepoScanResource;
use App\Http\Resources\RepositoryResource;
use App\Models\Asset;
use App\Models\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RepositoryController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $repositories = Repository::query()
            ->with(['assets:id,value'])
            ->withCount(['findings as open_findings_count' => fn ($query) => $query->issues()->open()])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('q'), fn ($query) => $query->where('full_name', 'like', '%'.$request->string('q').'%'))
            ->orderBy('full_name')
            ->paginate($this->perPage($request));

        return RepositoryResource::collection($repositories)->response();
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'github_credential_id' => ['required', 'uuid', 'exists:github_credentials,id'],
            'target_ids' => ['sometimes', 'array'],
            'target_ids.*' => ['uuid'],
        ]);

        $this->assertIds(Asset::class, $data['target_ids'] ?? []);

        $attributes = $this->domain(fn (): array => RepositoryForm::hydrateFromGithub($data));

        $repository = Repository::query()->create($attributes);

        if (! empty($data['target_ids'])) {
            $repository->assets()->sync($data['target_ids']);
        }

        return (new RepositoryResource($repository->load('assets:id,value')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Repository $repository): RepositoryResource
    {
        $repository->load(['assets:id,value', 'latestScan.tasks'])
            ->loadCount(['findings as open_findings_count' => fn ($query) => $query->issues()->open()]);

        return new RepositoryResource($repository);
    }

    public function update(Request $request, Repository $repository): RepositoryResource
    {
        $data = $request->validate([
            'github_credential_id' => ['sometimes', 'uuid', 'exists:github_credentials,id'],
            'default_branch' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'in:active,paused'],
        ]);

        $repository->update($data);

        return new RepositoryResource($repository->fresh()->load('assets:id,value'));
    }

    public function destroy(Repository $repository): JsonResponse
    {
        $repository->delete();

        return response()->json(null, 204);
    }

    public function pause(Repository $repository): RepositoryResource
    {
        $repository->update(['status' => 'paused']);

        return new RepositoryResource($repository->fresh());
    }

    public function resume(Repository $repository): RepositoryResource
    {
        $repository->update(['status' => 'active']);

        return new RepositoryResource($repository->fresh());
    }

    public function refresh(Repository $repository): RepositoryResource
    {
        $credential = $repository->credential;

        if (! $credential) {
            $this->fail('Repository has no GitHub credential.');
        }

        $remote = $this->domain(fn (): array => app(GithubClient::class)->fetchRepository(
            $credential,
            $repository->owner,
            $repository->name,
        ));

        $repository->update([
            'default_branch' => (string) ($remote['default_branch'] ?? $repository->default_branch),
            'is_private' => (bool) ($remote['private'] ?? $repository->is_private),
            'html_url' => (string) ($remote['html_url'] ?? $repository->html_url),
            'meta' => [
                'description' => $remote['description'] ?? null,
                'language' => $remote['language'] ?? null,
                'topics' => $remote['topics'] ?? [],
            ],
        ]);

        return new RepositoryResource($repository->fresh());
    }

    public function syncTargets(Request $request, Repository $repository): RepositoryResource
    {
        $data = $request->validate([
            'target_ids' => ['present', 'array'],
            'target_ids.*' => ['uuid'],
        ]);

        $this->assertIds(Asset::class, $data['target_ids']);
        $repository->assets()->sync($data['target_ids']);

        return new RepositoryResource($repository->fresh()->load('assets:id,value'));
    }

    public function scan(Request $request, Repository $repository): JsonResponse
    {
        $data = $request->validate([
            'profile' => ['required', Rule::enum(ScanProfile::class)],
            'include_targets' => ['sometimes', 'boolean'],
        ]);

        $result = $this->domain(fn () => app(RepoScanDispatcher::class)->createScan(
            $repository,
            ScanProfile::from($data['profile']),
            $request->user(),
            includeLinkedTargets: $request->boolean('include_targets'),
            linkedTargetProfile: ScanProfile::Deep,
        ));

        $scan = $result['scan']->load(['repository', 'tasks', 'requester']);

        return response()->json([
            'data' => (new RepoScanResource($scan))->resolve($request),
            'linked_target_scan_ids' => $result['linked_target_scans'],
        ], 201);
    }
}
