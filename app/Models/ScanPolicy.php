<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScanPolicy extends Model
{
    protected $fillable = [
        'name',
        'is_default',
        'per_target_per_minute',
        'global_concurrent',
        'jitter_seconds',
        'task_spacing_seconds',
        'deep_cooldown_hours',
        'quiet_hours_enabled',
        'quiet_hours_start',
        'quiet_hours_end',
        'timezone',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'quiet_hours_enabled' => 'boolean',
        ];
    }

    public static function defaultPolicy(): self
    {
        $policy = static::query()->where('is_default', true)->first();

        if ($policy) {
            return $policy;
        }

        return static::query()->create([
            'name' => 'Default',
            'is_default' => true,
            'per_target_per_minute' => (int) config('hackly.rate_limits.per_target_per_minute'),
            'global_concurrent' => (int) config('hackly.rate_limits.global_concurrent'),
            'jitter_seconds' => (int) config('hackly.rate_limits.jitter_seconds'),
            'task_spacing_seconds' => (int) config('hackly.rate_limits.task_spacing_seconds'),
            'deep_cooldown_hours' => (int) config('hackly.rate_limits.deep_cooldown_hours'),
            'quiet_hours_enabled' => (bool) config('hackly.quiet_hours.enabled'),
            'quiet_hours_start' => (int) config('hackly.quiet_hours.start'),
            'quiet_hours_end' => (int) config('hackly.quiet_hours.end'),
            'timezone' => (string) config('hackly.quiet_hours.timezone'),
        ]);
    }
}
