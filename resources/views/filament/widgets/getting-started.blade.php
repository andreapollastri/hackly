<x-filament-widgets::widget>
    <section class="hk-onboarding">
        <header class="hk-onboarding__head">
            <div>
                <p class="hk-eyebrow">Getting started</p>
                <h2 class="hk-onboarding__title">Put your attack surface under watch</h2>
                <p class="hk-onboarding__lead">{{ $done }} of {{ count($steps) }} steps done. Hackly only scans what you prove you own.</p>
            </div>
            <div class="hk-onboarding__ring" style="--hk-ring: {{ (int) round($done / max(count($steps), 1) * 100) }}">
                <span>{{ $done }}/{{ count($steps) }}</span>
            </div>
        </header>

        <ol class="hk-onboarding__steps">
            @foreach ($steps as $i => $step)
                <li @class(['hk-onboarding__step', 'is-done' => $step['done']])>
                    <span class="hk-onboarding__check">
                        @if ($step['done'])
                            <x-filament::icon icon="heroicon-m-check" />
                        @else
                            {{ $i + 1 }}
                        @endif
                    </span>
                    <div class="hk-onboarding__body">
                        <p class="hk-onboarding__step-title">{{ $step['title'] }}</p>
                        <p class="hk-onboarding__step-text">{{ $step['text'] }}</p>
                        @unless ($step['done'])
                            <a href="{{ $step['url'] }}" class="hk-link" wire:navigate>
                                {{ $step['cta'] }}
                                <x-filament::icon icon="heroicon-m-arrow-right" class="hk-link__icon" />
                            </a>
                        @endunless
                    </div>
                </li>
            @endforeach
        </ol>

        @if ($missingScanners > 0)
            <a href="{{ $healthUrl }}" class="hk-alert hk-alert--warning hk-onboarding__health" wire:navigate>
                <x-filament::icon icon="heroicon-o-wrench-screwdriver" class="hk-alert__icon" />
                <span>{{ $missingScanners }} scanner {{ \Illuminate\Support\Str::plural('binary', $missingScanners) }} not found on this host — those tasks will be skipped. <strong>Check scanner health →</strong></span>
            </a>
        @endif
    </section>
</x-filament-widgets::widget>
