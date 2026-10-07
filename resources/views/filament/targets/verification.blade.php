@php
    /** @var \App\Models\Asset $asset */
    $rows = [
        ['label' => 'Type', 'value' => 'TXT'],
        ['label' => 'Host / name', 'value' => $asset->verificationHost(), 'hint' => 'Use “@” if your DNS provider expects a relative name.'],
        ['label' => 'Value', 'value' => $token],
    ];
@endphp

<div class="hk-verify">
    <ol class="hk-steps">
        <li class="hk-steps__item">
            <span class="hk-steps__n">1</span>
            <div>
                <p class="hk-steps__title">Add this TXT record at your DNS provider</p>
                <div class="hk-record">
                    @foreach ($rows as $row)
                        <div class="hk-record__row">
                            <span class="hk-record__label">{{ $row['label'] }}</span>
                            <code class="hk-record__value">{{ $row['value'] }}</code>
                            <button
                                type="button"
                                class="hk-copy"
                                x-data="{ copied: false }"
                                x-on:click="window.navigator.clipboard.writeText(@js($row['value'])); copied = true; setTimeout(() => copied = false, 1500)"
                                x-bind:class="{ 'is-copied': copied }"
                                aria-label="Copy {{ strtolower($row['label']) }}"
                            >
                                <span x-show="! copied">Copy</span>
                                <span x-show="copied" x-cloak>Copied</span>
                            </button>
                        </div>
                        @if (! empty($row['hint']))
                            <p class="hk-record__hint">{{ $row['hint'] }}</p>
                        @endif
                    @endforeach
                </div>
            </div>
        </li>
        <li class="hk-steps__item">
            <span class="hk-steps__n">2</span>
            <div>
                <p class="hk-steps__title">Wait for DNS propagation</p>
                <p class="hk-steps__text">Usually a few minutes; some providers take up to an hour. Existing TXT records (SPF, etc.) can stay — just add a new one.</p>
            </div>
        </li>
        <li class="hk-steps__item">
            <span class="hk-steps__n">3</span>
            <div>
                <p class="hk-steps__title">Check DNS</p>
                <p class="hk-steps__text">Hackly looks up the TXT records of <strong>{{ $asset->value }}</strong>. Once the token is found the target is unlocked for scanning. You can remove the record afterwards.</p>
            </div>
        </li>
    </ol>
</div>
