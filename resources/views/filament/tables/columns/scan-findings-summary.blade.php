@php
    /** @var array{high: int, medium: int, low: int} $summary */
    $summary = $getState() ?? ['high' => 0, 'medium' => 0, 'low' => 0];
@endphp

<div
    class="fi-scan-findings-summary flex items-center gap-1"
    wire:key="scan-findings-{{ $getRecord()->id }}-{{ $summary['high'] }}-{{ $summary['medium'] }}-{{ $summary['low'] }}"
>
    <span
        class="fi-badge fi-size-sm fi-color-danger {{ $summary['high'] === 0 ? 'fi-incomplete opacity-50' : '' }}"
        title="High"
    >
        <span class="fi-badge-label">{{ $summary['high'] }}</span>
    </span>
    <span
        class="fi-badge fi-size-sm fi-color-warning {{ $summary['medium'] === 0 ? 'fi-incomplete opacity-50' : '' }}"
        title="Medium"
    >
        <span class="fi-badge-label">{{ $summary['medium'] }}</span>
    </span>
    <span
        class="fi-badge fi-size-sm fi-color-success {{ $summary['low'] === 0 ? 'fi-incomplete opacity-50' : '' }}"
        title="Low"
    >
        <span class="fi-badge-label">{{ $summary['low'] }}</span>
    </span>
</div>
