<?php

namespace App\Models;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Finding extends Model
{
    protected $fillable = [
        'asset_id',
        'scan_id',
        'scan_task_id',
        'severity',
        'title',
        'category',
        'cve',
        'source',
        'status',
        'fingerprint',
        'evidence',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'severity' => FindingSeverity::class,
            'status' => FindingStatus::class,
            'evidence' => 'array',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function scanTask(): BelongsTo
    {
        return $this->belongsTo(ScanTask::class);
    }
}
