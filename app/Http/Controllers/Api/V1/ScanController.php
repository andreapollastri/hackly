<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Scanning\Services\ScanDispatcher;
use App\Enums\ScanProfile;
use App\Filament\Resources\Scans\ScanResource as ScanReport;
use App\Http\Resources\ScanResource;
use App\Models\Scan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScanController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $scans = Scan::query()
            ->with(['asset:id,value', 'requester:id,name,email', 'tasks:id,scan_id,type,status,attempts,sort_order,scheduled_at,started_at,finished_at,error_message'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('profile'), fn ($query) => $query->where('profile', $request->string('profile')))
            ->when($request->filled('target_id'), fn ($query) => $query->where('asset_id', $request->string('target_id')))
            ->latest()
            ->paginate($this->perPage($request));

        return ScanResource::collection($scans)->response();
    }

    public function show(Scan $scan): ScanResource
    {
        $scan->load(['asset', 'requester', 'tasks']);

        return new ScanResource($scan);
    }

    public function cancel(Scan $scan): ScanResource
    {
        if (! $scan->canBeCancelled()) {
            $this->fail('This scan is not running.');
        }

        $scan->cancel();

        return new ScanResource($scan->fresh()->load(['asset', 'requester', 'tasks']));
    }

    public function rerun(Request $request, Scan $scan): JsonResponse
    {
        $asset = $scan->asset;

        if (! $asset) {
            $this->fail('The target for this scan no longer exists.');
        }

        $result = $this->domain(fn () => app(ScanDispatcher::class)->createScan(
            $asset,
            $scan->profile ?? ScanProfile::Standard,
            $request->user(),
        ));

        return response()->json([
            'data' => (new ScanResource($result['scan']->load(['asset', 'tasks', 'requester'])))->resolve($request),
            'linked_repository_scan_ids' => $result['linked_repo_scans'],
        ], 201);
    }

    public function report(Scan $scan, string $format): StreamedResponse
    {
        return match ($format) {
            'pdf' => ScanReport::downloadReport($scan),
            'markdown', 'md' => ScanReport::downloadMarkdownReport($scan),
            default => $this->fail('Format must be pdf or markdown.', 404),
        };
    }
}
