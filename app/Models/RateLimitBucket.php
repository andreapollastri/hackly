<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RateLimitBucket extends Model
{
    use HasUuids;

    protected $fillable = [
        'target_key',
        'tokens_used',
        'window_starts_at',
    ];

    protected function casts(): array
    {
        return [
            'window_starts_at' => 'datetime',
        ];
    }
}
