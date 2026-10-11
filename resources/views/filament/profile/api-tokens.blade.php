<div class="hk-api-tokens">
    @if ($plainTextToken)
        <div class="hk-alert hk-alert--warning">
            <div>
                <strong>{{ $issuedTokenName }}</strong> is ready. Copy it now — it will not be shown again.
                <input class="hk-token" type="text" readonly value="{{ $plainTextToken }}" onclick="this.select()">
                <button type="button" wire:click="$set('plainTextToken', null)">Dismiss</button>
            </div>
        </div>
    @endif

    @if ($tokens->isEmpty())
        <p class="hk-api-tokens__empty">No tokens yet. Create one to call <code>/api/v1</code>.</p>
    @else
        <ul class="hk-api-tokens__list">
            @foreach ($tokens as $token)
                <li>
                    <div>
                        <strong>{{ $token->name }}</strong>
                        <span>{{ \App\Support\ApiTokenAbilities::label($token->abilities ?? []) }}</span>
                        <span class="hk-api-tokens__meta">
                            Created {{ $token->created_at?->diffForHumans() }}
                            · {{ $token->last_used_at ? 'used '.$token->last_used_at->diffForHumans() : 'never used' }}
                        </span>
                    </div>
                    <button type="button" wire:click="revokeApiToken({{ $token->id }})" wire:confirm="Revoke {{ $token->name }}?">Revoke</button>
                </li>
            @endforeach
        </ul>
    @endif

    <p class="hk-api-tokens__docs">
        <a href="{{ route('api.docs') }}" target="_blank" rel="noopener">Open the API reference</a>
        · send <code>Authorization: Bearer hk_…</code>
    </p>
</div>
