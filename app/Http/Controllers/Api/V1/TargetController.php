<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Scanning\Services\DnsOwnershipVerifier;
use App\Domain\Scanning\Services\ScanDispatcher;
use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ScanProfile;
use App\Filament\Resources\Assets\AssetResource as AssetForm;
use App\Http\Resources\ScanResource;
use App\Http\Resources\TargetResource;
use App\Models\Asset;
use App\Models\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TargetController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $targets = Asset::query()
            ->with(['repositories:id,full_name'])
            ->withCount(['findings as open_findings_count' => fn ($query) => $query->issues()->open()])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('q'), fn ($query) => $query->where('value', 'like', '%'.$request->string('q').'%'))
            ->orderBy('value')
            ->paginate($this->perPage($request));

        return TargetResource::collection($targets)->response();
    }

    public function store(Request $request): JsonResponse
    {
        $domain = AssetForm::normalizeDomain((string) $request->input('domain', $request->input('value')));

        $data = validator([
            'domain' => $domain,
            'authorization_note' => $request->input('authorization_note'),
        ], [
            'domain' => ['required', 'string', 'max:253', 'regex:/^(?!-)(?:[a-z0-9-]{1,63}\.)+[a-z]{2,63}$/i', 'unique:assets,value'],
            'authorization_note' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        $target = Asset::query()->create([
            'type' => AssetType::Domain,
            'value' => $data['domain'],
            'status' => AssetStatus::Active,
            'authorization_note' => $data['authorization_note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        app(DnsOwnershipVerifier::class)->issueToken($target);

        return (new TargetResource($target->fresh()))->response()->setStatusCode(201);
    }

    public function show(Asset $target): TargetResource
    {
        $target->load(['repositories:id,full_name', 'latestScan.tasks'])
            ->loadCount(['findings as open_findings_count' => fn ($query) => $query->issues()->open()]);

        return new TargetResource($target);
    }

    public function update(Request $request, Asset $target): TargetResource
    {
        $data = $request->validate([
            'domain' => ['sometimes', 'string', 'max:253'],
            'value' => ['sometimes', 'string', 'max:253'],
            'authorization_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::enum(AssetStatus::class)],
        ]);

        if (array_key_exists('domain', $data) || array_key_exists('value', $data)) {
            if ($target->isVerified()) {
                $this->fail('The domain is locked after DNS verification.');
            }

            $domain = AssetForm::normalizeDomain((string) ($data['domain'] ?? $data['value']));
            validator(['domain' => $domain], [
                'domain' => ['required', 'regex:/^(?!-)(?:[a-z0-9-]{1,63}\.)+[a-z]{2,63}$/i', Rule::unique('assets', 'value')->ignore($target->id)],
            ])->validate();
            $target->value = $domain;
        }

        if (array_key_exists('authorization_note', $data)) {
            $target->authorization_note = $data['authorization_note'];
        }

        if (array_key_exists('status', $data)) {
            $target->status = $data['status'];
        }

        $target->save();

        if ($target->wasChanged('value')) {
            app(DnsOwnershipVerifier::class)->issueToken($target);
        }

        return new TargetResource($target->fresh()->load('repositories:id,full_name'));
    }

    public function destroy(Asset $target): JsonResponse
    {
        $target->delete();

        return response()->json(null, 204);
    }

    public function pause(Asset $target): TargetResource
    {
        $target->update(['status' => AssetStatus::Paused]);

        return new TargetResource($target->fresh());
    }

    public function resume(Asset $target): TargetResource
    {
        $target->update(['status' => AssetStatus::Active]);

        return new TargetResource($target->fresh());
    }

    public function issueVerification(Asset $target): TargetResource
    {
        if (! $target->isVerified()) {
            app(DnsOwnershipVerifier::class)->ensureToken($target);
        }

        return new TargetResource($target->fresh());
    }

    public function checkVerification(Asset $target): TargetResource
    {
        if (! $target->isVerified()) {
            $this->domain(fn () => app(DnsOwnershipVerifier::class)->verify($target));
        }

        return new TargetResource($target->fresh());
    }

    public function regenerateVerification(Asset $target): TargetResource
    {
        if ($target->isVerified()) {
            $this->fail('This target is already verified.');
        }

        app(DnsOwnershipVerifier::class)->issueToken($target);

        return new TargetResource($target->fresh());
    }

    public function syncRepositories(Request $request, Asset $target): TargetResource
    {
        $data = $request->validate([
            'repository_ids' => ['present', 'array'],
            'repository_ids.*' => ['uuid'],
        ]);

        $this->assertIds(Repository::class, $data['repository_ids']);
        $target->repositories()->sync($data['repository_ids']);

        return new TargetResource($target->fresh()->load('repositories:id,full_name'));
    }

    public function scan(Request $request, Asset $target): JsonResponse
    {
        $data = $request->validate([
            'profile' => ['required', Rule::enum(ScanProfile::class)],
            'include_repositories' => ['sometimes', 'boolean'],
        ]);

        $result = $this->domain(fn () => app(ScanDispatcher::class)->createScan(
            $target,
            ScanProfile::from($data['profile']),
            $request->user(),
            includeLinkedRepos: $request->boolean('include_repositories'),
        ));

        $scan = $result['scan']->load(['asset', 'tasks', 'requester']);

        return response()->json([
            'data' => (new ScanResource($scan))->resolve($request),
            'linked_repository_scan_ids' => $result['linked_repo_scans'],
        ], 201);
    }
}
