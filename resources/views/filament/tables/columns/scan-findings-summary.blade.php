@php
    /** @var array{high: int, medium: int, low: int} $summary */
    $summary = $getState() ?? ['high' => 0, 'medium' => 0, 'low' => 0];
@endphp

<div
    class="fi-scan-findings-summary flex items-center gap-1.5"
    wire:key="scan-findings-{{ $getRecord()->id }}-{{ $summary['high'] }}-{{ $summary['medium'] }}-{{ $summary['low'] }}"
    title="High {{ $summary['high'] }} · Medium {{ $summary['medium'] }} · Low {{ $summary['low'] }}"
>
    <span
        class="inline-flex min-w-7 items-center justify-center rounded-md px-1.5 py-0.5 text-xs font-bold text-white"
        style="background:#dc2626;"
    >{{ $summary['high'] }}</span>
    <span
        class="inline-flex min-w-7 items-center justify-center rounded-md px-1.5 py-0.5 text-xs font-bold text-white"
        style="background:#ea580c;"
    >{{ $summary['medium'] }}</span>
    <span
        class="inline-flex min-w-7 items-center justify-center rounded-md px-1.5 py-0.5 text-xs font-bold text-white"
        style="background:#16a34a;"
    >{{ $summary['low'] }}</span>
</div>
