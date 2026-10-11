<?php

namespace App\Http\Resources;

use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RepositoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Repository $repository */
        $repository = $this->resource;

        return [
            'id' => $repository->id,
            'owner' => $repository->owner,
            'name' => $repository->name,
            'full_name' => $repository->full_name,
            'default_branch' => $repository->default_branch,
            'is_private' => (bool) $repository->is_private,
            'status' => $repository->status,
            'html_url' => $repository->html_url,
            'description' => $repository->description(),
            'language' => $repository->language(),
            'topics' => $repository->meta['topics'] ?? [],
            'last_scanned_at' => $repository->last_scanned_at?->toIso8601String(),
            'last_commit_sha' => $repository->last_commit_sha,
            'github_credential_id' => $repository->github_credential_id,
            'open_findings' => $this->when(isset($repository->open_findings_count), (int) $repository->open_findings_count),
            'targets' => $this->whenLoaded('assets', fn () => $repository->assets->map(fn ($asset): array => [
                'id' => $asset->id,
                'domain' => $asset->value,
            ])->values()),
            'latest_scan' => new RepoScanResource($this->whenLoaded('latestScan')),
            'created_at' => $repository->created_at?->toIso8601String(),
            'updated_at' => $repository->updated_at?->toIso8601String(),
        ];
    }
}
