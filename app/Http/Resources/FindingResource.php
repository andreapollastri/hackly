<?php

namespace App\Http\Resources;

use App\Models\Finding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FindingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Finding $finding */
        $finding = $this->resource;

        return [
            'id' => $finding->id,
            'title' => $finding->title,
            'severity' => $finding->severity?->value,
            'status' => $finding->status?->value,
            'category' => $finding->category,
            'category_label' => Finding::categoryLabel($finding->category),
            'source' => $finding->source,
            'cve' => $finding->cve,
            'reachability' => $finding->reachability?->value,
            'confidence' => $finding->confidence,
            'noise_filtered' => (bool) $finding->noise_filtered,
            'description' => $finding->description,
            'false_positive_note' => $finding->false_positive_note,
            'false_positive_noted_at' => $finding->false_positive_noted_at?->toIso8601String(),
            'shared' => $finding->isPublished(),
            'share_url' => $finding->publicUrl(),
            'evidence' => $finding->evidence,
            'evidence_lines' => $finding->evidenceDetailLines(),
            'subject' => $finding->subjectName(),
            'asset' => $this->when($finding->relationLoaded('asset') && $finding->asset, fn (): array => [
                'id' => $finding->asset->id,
                'domain' => $finding->asset->value,
            ]),
            'repository' => $this->when($finding->relationLoaded('repository') && $finding->repository, fn (): array => [
                'id' => $finding->repository->id,
                'full_name' => $finding->repository->full_name,
            ]),
            'scan_id' => $finding->scan_id,
            'repo_scan_id' => $finding->repo_scan_id,
            'created_at' => $finding->created_at?->toIso8601String(),
            'updated_at' => $finding->updated_at?->toIso8601String(),
        ];
    }
}
