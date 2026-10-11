<?php

namespace App\Http\Resources;

use App\Support\ApiTokenAbilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

class TokenResource extends JsonResource
{
    public function __construct($resource, public ?string $plainText = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PersonalAccessToken $token */
        $token = $this->resource;
        $abilities = is_array($token->abilities) ? $token->abilities : [];

        return [
            'id' => $token->id,
            'name' => $token->name,
            'abilities' => $abilities,
            'ability_label' => ApiTokenAbilities::label($abilities),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'plain_text_token' => $this->when($this->plainText !== null, $this->plainText),
        ];
    }
}
