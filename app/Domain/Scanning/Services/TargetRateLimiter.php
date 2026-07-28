<?php

namespace App\Domain\Scanning\Services;

use App\Models\RateLimitBucket;
use App\Models\ScanPolicy;
use App\Models\ScanTask;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class TargetRateLimiter
{
    public function __construct(
        private readonly ScanPolicy $policy,
    ) {}

    public static function fromDefaultPolicy(): self
    {
        return new self(ScanPolicy::defaultPolicy());
    }

    public function withinQuietHours(?Carbon $at = null): bool
    {
        if (! $this->policy->quiet_hours_enabled) {
            return false;
        }

        $at ??= now($this->policy->timezone);
        $hour = (int) $at->timezone($this->policy->timezone)->format('G');
        $start = (int) $this->policy->quiet_hours_start;
        $end = (int) $this->policy->quiet_hours_end;

        if ($start === $end) {
            return false;
        }

        if ($start < $end) {
            return $hour >= $start && $hour < $end;
        }

        return $hour >= $start || $hour < $end;
    }

    public function canDispatch(string $targetKey): bool
    {
        if ($this->withinQuietHours()) {
            return false;
        }

        if ($this->globalConcurrent() >= $this->policy->global_concurrent) {
            return false;
        }

        return $this->tokensUsed($targetKey) < $this->policy->per_target_per_minute;
    }

    public function hit(string $targetKey): void
    {
        $bucket = RateLimitBucket::query()->firstOrCreate(
            ['target_key' => $targetKey],
            [
                'tokens_used' => 0,
                'window_starts_at' => now()->startOfMinute(),
            ]
        );

        if ($bucket->window_starts_at->lt(now()->startOfMinute())) {
            $bucket->update([
                'tokens_used' => 1,
                'window_starts_at' => now()->startOfMinute(),
            ]);

            return;
        }

        $bucket->increment('tokens_used');
    }

    public function tokensUsed(string $targetKey): int
    {
        $bucket = RateLimitBucket::query()->where('target_key', $targetKey)->first();

        if (! $bucket) {
            return 0;
        }

        if ($bucket->window_starts_at->lt(now()->startOfMinute())) {
            return 0;
        }

        return (int) $bucket->tokens_used;
    }

    public function globalConcurrent(): int
    {
        return ScanTask::query()
            ->whereIn('status', ['queued', 'running'])
            ->count();
    }

    public function acquireGlobalSlot(string $lockKey, int $seconds = 30): bool
    {
        return Cache::lock('hackly:global:'.$lockKey, $seconds)->get();
    }

    public function jitterSeconds(): int
    {
        $max = max(0, (int) $this->policy->jitter_seconds);

        return $max === 0 ? 0 : random_int(0, $max);
    }

    public function taskSpacingSeconds(): int
    {
        return (int) $this->policy->task_spacing_seconds;
    }

    public function policy(): ScanPolicy
    {
        return $this->policy;
    }
}
