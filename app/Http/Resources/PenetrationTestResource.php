<?php

namespace App\Http\Resources;

use App\Domain\Scanning\Services\ScannerEgress;
use App\Models\PenetrationTest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PenetrationTestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PenetrationTest $pentest */
        $pentest = $this->resource;

        return [
            'id' => $pentest->id,
            'label' => $pentest->label,
            'technology' => $pentest->technology?->value,
            'technology_notes' => $pentest->technology_notes,
            'swagger_url' => $pentest->swagger_url,
            'login_urls' => $pentest->login_urls ?? [],
            'extra_urls' => $pentest->extra_urls ?? [],
            'ports' => $pentest->ports ?? [],
            'notes' => $pentest->notes,
            'authentication_notes' => $pentest->authentication_notes,
            'out_of_scope' => $pentest->out_of_scope,
            'has_test_account' => filled($pentest->test_username),
            'scanner_ip' => app(ScannerEgress::class)->address(),
            'authorization' => [
                'state' => $pentest->authorizationLabel(),
                'ready' => $pentest->isAuthorizationReady(),
                'record_type' => 'TXT',
                'host' => $pentest->asset?->value,
                'token' => $pentest->authorization_token,
                'verified_at' => $pentest->authorization_verified_at?->toIso8601String(),
                'consumed_at' => $pentest->authorization_consumed_at?->toIso8601String(),
                'scanner_ip' => app(ScannerEgress::class)->address(),
            ],
            'target' => $this->whenLoaded('asset', fn (): array => [
                'id' => $pentest->asset?->id,
                'domain' => $pentest->asset?->value,
            ]),
            'created_at' => $pentest->created_at?->toIso8601String(),
            'updated_at' => $pentest->updated_at?->toIso8601String(),
        ];
    }
}
