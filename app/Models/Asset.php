<?php

namespace App\Models;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    protected $fillable = [
        'type',
        'value',
        'status',
        'authorization_note',
        'verified_at',
        'verification_token',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => AssetType::class,
            'status' => AssetStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    public function targetKey(): string
    {
        return strtolower($this->value);
    }

    public function isDomain(): bool
    {
        return $this->type === AssetType::Domain;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function httpBaseUrl(): string
    {
        if ($this->isDomain()) {
            return 'https://'.$this->value;
        }

        return 'http://'.$this->value;
    }
}
