<?php

namespace App\Http\Resources;

use App\Models\RepoScan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RepoScanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RepoScan $scan */
        $scan = $this->resource;

        return [
            'id' => $scan->id,
            'kind' => 'repository',
            'profile' => $scan->profile?->value,
            'profile_label' => $scan->profile?->getLabel(),
            'status' => $scan->status?->value,
            'status_label' => $scan->status?->getLabel(),
            'progress_percent' => $scan->progressPercent(),
            'duration_seconds' => $scan->durationInSeconds(),
            'findings_summary' => $scan->findingsSeveritySummary(),
            'commit_sha' => $scan->commit_sha,
            'include_linked_targets' => (bool) ($scan->meta['include_linked_targets'] ?? false),
            'linked_target_scan_ids' => $scan->meta['linked_target_scan_ids'] ?? [],
            'error_message' => $scan->error_message,
            'repository' => $this->when($scan->relationLoaded('repository'), fn (): ?array => $scan->repository ? [
                'id' => $scan->repository->id,
                'full_name' => $scan->repository->full_name,
            ] : null),
            'requested_by' => new UserSnippetResource($this->whenLoaded('requester')),
            'tasks' => RepoScanTaskResource::collection($this->whenLoaded('tasks')),
            'started_at' => $scan->started_at?->toIso8601String(),
            'finished_at' => $scan->finished_at?->toIso8601String(),
            'created_at' => $scan->created_at?->toIso8601String(),
        ];
    }
}
