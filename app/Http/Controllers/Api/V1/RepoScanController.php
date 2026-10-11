<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\RepoScanning\Services\RepoScanDispatcher;
use App\Enums\ScanProfile;
use App\Filament\Resources\RepoScans\RepoScanResource as RepoScanReport;
use App\Http\Resources\RepoScanResource;
use App\Models\RepoScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RepoScanController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $scans = RepoScan::query()
            ->with(['repository:id,full_name', 'requester:id,name,email', 'tasks:id,repo_scan_id,type,status,attempts,sort_order,scheduled_at,started_at,finished_at,error_message'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('profile'), fn ($query) => $query->where('profile', $request->string('profile')))
            ->when($request->filled('repository_id'), fn ($query) => $query->where('repository_id', $request->string('repository_id')))
            ->latest()
            ->paginate($this->perPage($request));

        return RepoScanResource::collection($scans)->response();
    }

    public function show(RepoScan $repoScan): RepoScanResource
    {
        $repoScan->load(['repository', 'requester', 'tasks']);

        return new RepoScanResource($repoScan);
    }

    public function cancel(RepoScan $repoScan): RepoScanResource
    {
        if (! $repoScan->canBeCancelled()) {
            $this->fail('This scan is not running.');
        }

        $repoScan->cancel();

        return new RepoScanResource($repoScan->fresh()->load(['repository', 'requester', 'tasks']));
    }

    public function rerun(Request $request, RepoScan $repoScan): JsonResponse
    {
        $repository = $repoScan->repository;

        if (! $repository) {
            $this->fail('The repository for this scan no longer exists.');
        }

        $result = $this->domain(fn () => app(RepoScanDispatcher::class)->createScan(
            $repository,
            $repoScan->profile ?? ScanProfile::Standard,
            $request->user(),
        ));

        return response()->json([
            'data' => (new RepoScanResource($result['scan']->load(['repository', 'tasks', 'requester'])))->resolve($request),
            'linked_target_scan_ids' => $result['linked_target_scans'],
        ], 201);
    }

    public function report(RepoScan $repoScan, string $format): StreamedResponse
    {
        return match ($format) {
            'pdf' => RepoScanReport::downloadReport($repoScan),
            'markdown', 'md' => RepoScanReport::downloadMarkdownReport($repoScan),
            default => $this->fail('Format must be pdf or markdown.', 404),
        };
    }
}
