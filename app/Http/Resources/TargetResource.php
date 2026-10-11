<?php

namespace App\Http\Resources;

use App\Domain\Scanning\Services\ScannerEgress;
use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TargetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Asset $asset */
        $asset = $this->resource;

        return [
            'id' => $asset->id,
            'domain' => $asset->value,
            'status' => $asset->status?->value,
            'authorization_note' => $asset->authorization_note,
            'scanner_ip' => app(ScannerEgress::class)->address(),
            'verified' => $asset->isVerified(),
            'verified_at' => $asset->verified_at?->toIso8601String(),
            'verification' => $this->when(! $asset->isVerified(), fn (): array => [
                'record_type' => 'TXT',
                'host' => $asset->verificationHost(),
                'value' => $asset->verification_token,
                'scanner_ip' => app(ScannerEgress::class)->address(),
                'instructions' => 'Publish the value as a DNS TXT record on the host. Whitelist scanner_ip if the target filters inbound scans. Wait for propagation, then call the verification check.',
            ]),
            'open_findings' => $this->when(isset($asset->open_findings_count), (int) $asset->open_findings_count),
            'repositories' => $this->whenLoaded('repositories', fn () => $asset->repositories->map(fn ($repository): array => [
                'id' => $repository->id,
                'full_name' => $repository->full_name,
            ])->values()),
            'latest_scan' => new ScanResource($this->whenLoaded('latestScan')),
            'created_at' => $asset->created_at?->toIso8601String(),
            'updated_at' => $asset->updated_at?->toIso8601String(),
        ];
    }
}
