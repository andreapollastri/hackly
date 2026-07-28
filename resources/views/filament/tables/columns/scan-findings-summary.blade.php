@php
    /** @var array{high: int, medium: int, low: int} $summary */
    $summary = $getState() ?? ['high' => 0, 'medium' => 0, 'low' => 0];
@endphp

<div
    class="fi-scan-findings-summary flex items-center gap-1.5"
    wire:key="scan-findings-{{ $getRecord()->id }}-{{ $summary['high'] }}-{{ $summary['medium'] }}-{{ $summary['low'] }}"
>
    <x-filament::badge
        color="danger"
        size="sm"
        :tooltip="'High: '.$summary['high']"
        @class(['opacity-40' => $summary['high'] === 0])
    >
        {{ $summary['high'] }}
    </x-filament::badge>

    <x-filament::badge
        color="warning"
        size="sm"
        :tooltip="'Medium: '.$summary['medium']"
        @class(['opacity-40' => $summary['medium'] === 0])
    >
        {{ $summary['medium'] }}
    </x-filament::badge>

    <x-filament::badge
        color="success"
        size="sm"
        :tooltip="'Low: '.$summary['low']"
        @class(['opacity-40' => $summary['low'] === 0])
    >
        {{ $summary['low'] }}
    </x-filament::badge>
</div>
