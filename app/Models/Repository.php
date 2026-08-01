<?php

namespace App\Models;

use App\Enums\AssetStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Repository extends Model
{
    use HasUuids;

    protected $fillable = [
        'github_credential_id',
        'owner',
        'name',
        'full_name',
        'default_branch',
        'is_private',
        'nightly_enabled',
        'status',
        'html_url',
        'last_scanned_at',
        'last_commit_sha',
        'meta',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'nightly_enabled' => 'boolean',
            'last_scanned_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(GithubCredential::class, 'github_credential_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class)
            ->withTimestamps()
            ->using(AssetRepository::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(RepoScan::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function canonicalAsset(): ?Asset
    {
        return $this->assets()
            ->where('status', AssetStatus::Active->value)
            ->orderBy('value')
            ->first()
            ?? $this->assets()->orderBy('value')->first();
    }

    public function githubCloneUrl(): string
    {
        return 'https://github.com/'.$this->full_name.'.git';
    }
}
