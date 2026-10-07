@php
    /** @var array{high: int, medium: int, low: int}|null $summary */
    $summary = $getState();
@endphp

@if ($summary === null)
    <span class="hk-muted">—</span>
@else
    @include('filament.partials.severity-pills', ['summary' => $summary, 'key' => 'scan-findings-'.$getRecord()->getKey()])
@endif
