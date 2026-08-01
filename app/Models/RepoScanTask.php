<?php

namespace App\Models;

use App\Enums\RepoScanTaskType;
use App\Enums\ScanTaskStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RepoScanTask extends Model
{
    use HasUuids;

    protected $fillable = [
        'repo_scan_id',
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
            'type' => RepoScanTaskType::class,
            'status' => ScanTaskStatus::class,
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(RepoScan::class, 'repo_scan_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class, 'repo_scan_task_id');
    }
}
