@php
    /** @var array{high: int, medium: int, low: int} $summary */
    $levels = [
        'high' => ['label' => 'High', 'short' => 'H'],
        'medium' => ['label' => 'Medium', 'short' => 'M'],
        'low' => ['label' => 'Low', 'short' => 'L'],
    ];
    $total = array_sum($summary);
@endphp

<div class="hk-sev" wire:key="{{ $key ?? 'sev' }}-{{ implode('-', $summary) }}">
    @if ($total === 0 && ($showClean ?? true))
        <span class="hk-sev__clean">
            <x-filament::icon icon="heroicon-m-check" class="hk-sev__clean-icon" />
            No issues
        </span>
    @else
        @foreach ($levels as $level => $meta)
            <span
                @class(['hk-sev__pill', 'hk-sev__pill--'.$level, 'is-zero' => $summary[$level] === 0])
                title="{{ $summary[$level] }} {{ strtolower($meta['label']) }}"
            >
                <span class="hk-sev__letter">{{ $meta['short'] }}</span>{{ $summary[$level] }}
            </span>
        @endforeach
    @endif
</div>
