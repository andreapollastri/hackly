<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Compliance\CiGate;
use App\Domain\Compliance\PostureScore;
use App\Domain\Digest\WeeklyDigest;
use App\Domain\Scanning\Services\ScannerDatabaseUpdater;
use App\Enums\ScanProfile;
use App\Support\ScannerHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SystemController extends ApiController
{
    public function posture(PostureScore $posture): JsonResponse
    {
        return response()->json(['data' => $posture->snapshot()]);
    }

    public function digest(WeeklyDigest $digest): JsonResponse
    {
        return response()->json(['data' => $digest->build()]);
    }

    public function profiles(): JsonResponse
    {
        $profiles = collect(ScanProfile::cases())->map(fn (ScanProfile $profile): array => [
            'id' => $profile->value,
            'label' => $profile->getLabel(),
            'target' => [
                'description' => $profile->getDescription(),
                'tasks' => config("hackly.profiles.{$profile->value}", []),
            ],
            'repository' => [
                'description' => $profile->repositoryDescription(),
                'tasks' => config("hackly.repo.profiles.{$profile->value}", []),
            ],
        ])->values();

        return response()->json(['data' => $profiles]);
    }

    public function scannerHealth(): JsonResponse
    {
        return response()->json(['data' => $this->healthPayload()]);
    }

    public function recheckScannerHealth(): JsonResponse
    {
        return response()->json(['data' => $this->healthPayload(fresh: true)]);
    }

    public function ciGate(Request $request, CiGate $gate): JsonResponse
    {
        $data = $request->validate([
            'threshold' => ['sometimes', 'in:high,medium,low'],
            'include_acknowledged' => ['sometimes', 'boolean'],
            'require_scan' => ['sometimes', 'boolean'],
            'fail' => ['sometimes', 'boolean'],
        ]);

        $result = $gate->evaluate(
            threshold: $data['threshold'] ?? 'high',
            includeAcknowledged: $request->boolean('include_acknowledged'),
            requireScan: $request->boolean('require_scan'),
        );

        return response()->json(['data' => $result], $request->boolean('fail') && ! $result['pass'] ? 422 : 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function healthPayload(bool $fresh = false): array
    {
        $binaries = collect(ScannerHealth::binaries($fresh))
            ->map(fn (array $row, string $name): array => [
                'name' => $name,
                'available' => $row['available'],
                'path' => $row['path'],
                'resolved' => $row['resolved'],
                'hint' => $row['hint'],
                'used_by' => ScannerHealth::USED_BY[$name] ?? [],
                'group' => in_array($name, ScannerHealth::TARGET_BINARIES, true) ? 'target' : 'repository',
            ])
            ->values();

        return [
            'missing' => $binaries->where('available', false)->count(),
            'binaries' => $binaries,
            'databases' => ScannerDatabaseUpdater::lastRun(),
            'queue' => [
                'connection' => (string) config('queue.default'),
                'pending' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : null,
                'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
            ],
            'safety' => [
                'ownership_verification' => 'dns_txt',
                'allowlist_only' => (bool) config('hackly.allowlist_only'),
                'allowlist' => config('hackly.allowlist', []),
                'allow_private_targets' => (bool) config('hackly.allow_private_targets'),
                'per_target_per_minute' => config('hackly.rate_limits.per_target_per_minute'),
                'global_concurrent' => config('hackly.rate_limits.global_concurrent'),
                'task_spacing_seconds' => config('hackly.rate_limits.task_spacing_seconds'),
                'jitter_seconds' => config('hackly.rate_limits.jitter_seconds'),
                'deep_cooldown_hours' => config('hackly.rate_limits.deep_cooldown_hours'),
                'quiet_hours_enabled' => (bool) config('hackly.quiet_hours.enabled'),
            ],
        ];
    }
}
