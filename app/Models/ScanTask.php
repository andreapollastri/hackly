<?php

namespace App\Models;

use App\Enums\ScanTaskStatus;
use App\Enums\ScanTaskType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScanTask extends Model
{
    use HasUuids;

    protected $fillable = [
        'scan_id',
        'type',
        'queue',
        'status',
        'attempts',
        'sort_order',
        'scheduled_at',
        'started_at',
        'finished_at',
        'raw_output_path',
        'error_message',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'type' => ScanTaskType::class,
            'status' => ScanTaskStatus::class,
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }
}
