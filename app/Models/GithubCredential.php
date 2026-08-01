<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GithubCredential extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'token',
        'token_hint',
        'last_validated_at',
        'validation_status',
        'meta',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'last_validated_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    public static function hintFromToken(string $token): string
    {
        $token = trim($token);

        if (strlen($token) <= 8) {
            return '••••';
        }

        return substr($token, 0, 4).'…'.substr($token, -4);
    }
}
