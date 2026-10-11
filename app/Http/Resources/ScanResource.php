<?php

namespace App\Http\Resources;

use App\Models\Scan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Scan $scan */
        $scan = $this->resource;

        return [
            'id' => $scan->id,
            'kind' => 'target',
            'profile' => $scan->profile?->value,
            'profile_label' => $scan->profile?->getLabel(),
            'status' => $scan->status?->value,
            'status_label' => $scan->status?->getLabel(),
            'progress_percent' => $scan->progressPercent(),
            'duration_seconds' => $scan->durationInSeconds(),
            'findings_summary' => $scan->findingsSeveritySummary(),
            'error_message' => $scan->error_message,
            'asset' => $this->when($scan->relationLoaded('asset'), fn (): ?array => $scan->asset ? [
                'id' => $scan->asset->id,
                'domain' => $scan->asset->value,
            ] : null),
            'requested_by' => new UserSnippetResource($this->whenLoaded('requester')),
            'tasks' => ScanTaskResource::collection($this->whenLoaded('tasks')),
            'started_at' => $scan->started_at?->toIso8601String(),
            'finished_at' => $scan->finished_at?->toIso8601String(),
            'created_at' => $scan->created_at?->toIso8601String(),
        ];
    }
}
