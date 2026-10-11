<div class="hk-api-tokens">
    @if ($plainTextToken)
        <div class="hk-issued" x-data="{ copied: false }">
            <p class="hk-issued__lead">
                <strong>{{ $issuedTokenName }}</strong> is ready. Copy it now — it will not be shown again.
            </p>
            <div class="hk-issued__field">
                <code
                    class="hk-issued__value"
                    x-on:click="const range = document.createRange(); range.selectNodeContents($el); const selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range)"
                >{{ $plainTextToken }}</code>
                <button
                    type="button"
                    class="hk-copy hk-issued__copy"
                    x-on:click="window.hacklyCopy(@js($plainTextToken)); copied = true; setTimeout(() => copied = false, 1800)"
                    x-bind:class="{ 'is-copied': copied }"
                    x-bind:aria-label="copied ? 'Token copied' : 'Copy token'"
                >
                    <span class="hk-issued__copy-label" x-show="! copied">
                        <x-filament::icon icon="heroicon-o-clipboard-document" class="hk-issued__icon" />
                        Copy
                    </span>
                    <span class="hk-issued__copy-label" x-show="copied" x-cloak>
                        <x-filament::icon icon="heroicon-o-check" class="hk-issued__icon" />
                        Copied
                    </span>
                </button>
            </div>
            <button type="button" class="hk-issued__dismiss" wire:click="$set('plainTextToken', null)">I've saved it</button>
        </div>
    @endif

    @if ($tokens->isEmpty())
        <p class="hk-api-tokens__empty">No tokens yet. Create one to call <code>/api/v1</code>.</p>
    @else
        <ul class="hk-api-tokens__list">
            @foreach ($tokens as $token)
                <li>
                    <div class="hk-api-tokens__identity">
                        <strong>{{ $token->name }}</strong>
                        <span>{{ \App\Support\ApiTokenAbilities::label($token->abilities ?? []) }}</span>
                        <span class="hk-api-tokens__meta">
                            Created {{ $token->created_at?->diffForHumans() }}
                            · {{ $token->last_used_at ? 'used '.$token->last_used_at->diffForHumans() : 'never used' }}
                        </span>
                    </div>
                    <button type="button" class="hk-api-tokens__revoke" wire:click="revokeApiToken({{ $token->id }})" wire:confirm="Revoke {{ $token->name }}?">Revoke</button>
                </li>
            @endforeach
        </ul>
    @endif

    <p class="hk-api-tokens__docs">
        <a href="{{ route('api.docs') }}" target="_blank" rel="noopener">Open the API reference</a>
        · send <code>Authorization: Bearer hk_…</code>
    </p>
</div>
