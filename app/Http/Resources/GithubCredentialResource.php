<?php

namespace App\Http\Resources;

use App\Models\GithubCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GithubCredentialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var GithubCredential $credential */
        $credential = $this->resource;

        return [
            'id' => $credential->id,
            'name' => $credential->name,
            'token_hint' => $credential->token_hint,
            'validation_status' => $credential->validation_status,
            'github_login' => $credential->meta['login'] ?? null,
            'scopes' => $credential->meta['scopes'] ?? [],
            'repositories_count' => $this->when(isset($credential->repositories_count), (int) $credential->repositories_count),
            'last_validated_at' => $credential->last_validated_at?->toIso8601String(),
            'created_at' => $credential->created_at?->toIso8601String(),
            'updated_at' => $credential->updated_at?->toIso8601String(),
        ];
    }
}
