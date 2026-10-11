<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Findings\FindingBrief;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Http\Resources\FindingResource;
use App\Models\Finding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FindingController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $findings = $this->filtered($request)
            ->with(['asset:id,value', 'repository:id,full_name'])
            ->reorder()
            ->orderByRaw(FindingSeverity::orderByRankSql().' desc')
            ->orderByDesc('updated_at')
            ->paginate($this->perPage($request));

        return FindingResource::collection($findings)->response();
    }

    public function show(Finding $finding): FindingResource
    {
        $finding->load(['asset:id,value', 'repository:id,full_name']);

        return new FindingResource($finding);
    }

    public function update(Request $request, Finding $finding): FindingResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(FindingStatus::class)],
            'note' => ['nullable', 'string', 'max:'.Finding::FALSE_POSITIVE_NOTE_MAX],
            'false_positive_note' => ['nullable', 'string', 'max:'.Finding::FALSE_POSITIVE_NOTE_MAX],
        ]);

        $status = FindingStatus::from($data['status']);
        $finding->applyTriage($status, $this->falsePositiveNote($request, $status), $request->user()->id);

        return new FindingResource($finding->fresh()->load(['asset:id,value', 'repository:id,full_name']));
    }

    public function triage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['uuid'],
            'status' => ['required', Rule::enum(FindingStatus::class)],
            'note' => ['nullable', 'string', 'max:'.Finding::FALSE_POSITIVE_NOTE_MAX],
            'false_positive_note' => ['nullable', 'string', 'max:'.Finding::FALSE_POSITIVE_NOTE_MAX],
        ]);

        $this->assertIds(Finding::class, $data['ids']);

        $status = FindingStatus::from($data['status']);
        $note = $this->falsePositiveNote($request, $status);
        $userId = $request->user()->id;

        Finding::query()->whereKey($data['ids'])->each(function (Finding $finding) use ($status, $note, $userId): void {
            $finding->applyTriage($status, $note, $userId);
        });

        $findings = Finding::query()
            ->whereKey($data['ids'])
            ->with(['asset:id,value', 'repository:id,full_name'])
            ->get();

        return FindingResource::collection($findings)->response();
    }

    public function markdown(Finding $finding): Response
    {
        return response(FindingBrief::markdown($finding), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }

    public function prompt(Finding $finding): Response
    {
        return response(FindingBrief::prompt($finding), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }

    public function share(Request $request, Finding $finding): JsonResponse
    {
        $created = ! $finding->isPublished();
        $finding->publish($request->user()->id);

        return response()->json([
            'data' => [
                'share_url' => $finding->publicUrl(),
                'published_at' => $finding->published_at?->toIso8601String(),
            ],
        ], $created ? 201 : 200);
    }

    public function unshare(Finding $finding): JsonResponse
    {
        $finding->unpublish();

        return response()->json(null, 204);
    }

    private function falsePositiveNote(Request $request, FindingStatus $status): ?string
    {
        if ($status !== FindingStatus::FalsePositive) {
            return null;
        }

        $note = trim((string) $request->input('note', $request->input('false_positive_note')));

        if (mb_strlen($note) < Finding::FALSE_POSITIVE_NOTE_MIN) {
            throw ValidationException::withMessages([
                'note' => 'A note of at least '.Finding::FALSE_POSITIVE_NOTE_MIN.' characters is required when marking a finding as a false positive.',
            ]);
        }

        return $note;
    }

    private function filtered(Request $request)
    {
        $issues = ! $request->exists('issues') || $request->boolean('issues');

        return Finding::query()
            ->when($issues, fn ($query) => $query->issues())
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('severity'), fn ($query) => $query->where('severity', $request->string('severity')))
            ->when($request->filled('target_id'), fn ($query) => $query->where('asset_id', $request->string('target_id')))
            ->when($request->filled('repository_id'), fn ($query) => $query->where('repository_id', $request->string('repository_id')))
            ->when($request->filled('scan_id'), fn ($query) => $query->where('scan_id', $request->string('scan_id')))
            ->when($request->filled('repo_scan_id'), fn ($query) => $query->where('repo_scan_id', $request->string('repo_scan_id')))
            ->when($request->filled('source'), fn ($query) => $query->where('source', $request->string('source')))
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
            ->when($request->filled('q'), fn ($query) => $query->where('title', 'like', '%'.$request->string('q').'%'))
            ->when($request->exists('noise') && ! $request->boolean('noise'), fn ($query) => $query->where('noise_filtered', false));
    }
}
