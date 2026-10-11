<?php

namespace App\Models;

use App\Enums\PentestTechnology;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PenetrationTest extends Model
{
    use HasUuids;

    protected $fillable = [
        'asset_id',
        'label',
        'technology',
        'technology_notes',
        'swagger_url',
        'login_urls',
        'extra_urls',
        'ports',
        'notes',
        'authentication_notes',
        'out_of_scope',
        'test_username',
        'test_password',
        'authorization_token',
        'authorization_verified_at',
        'authorization_consumed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'technology' => PentestTechnology::class,
            'login_urls' => 'array',
            'extra_urls' => 'array',
            'ports' => 'array',
            'test_password' => 'encrypted',
            'authorization_verified_at' => 'datetime',
            'authorization_consumed_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class)->latest('created_at');
    }

    /**
     * The target already passed the standing DNS ownership check, so this run
     * does not need its own TXT record.
     */
    public function usesStandingAuthorization(): bool
    {
        $this->loadMissing('asset');

        return $this->asset?->isVerified() === true && $this->asset?->isActive() === true;
    }

    public function authorizationLabel(): string
    {
        if ($this->usesStandingAuthorization()) {
            return 'Covered by target';
        }

        if (blank($this->authorization_token)) {
            return 'Not requested';
        }

        if ($this->authorization_consumed_at !== null) {
            return 'Used';
        }

        if ($this->authorization_verified_at === null) {
            return 'Waiting for DNS';
        }

        $minutes = (int) config('hackly.pentest.authorization_minutes', 30);

        if ($this->authorization_verified_at->lt(now()->subMinutes($minutes))) {
            return 'Expired';
        }

        return 'Ready';
    }

    public function isAuthorizationReady(): bool
    {
        if ($this->usesStandingAuthorization()) {
            return true;
        }

        return $this->authorizationLabel() === 'Ready';
    }
}
