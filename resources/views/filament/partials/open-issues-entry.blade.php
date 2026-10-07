@php
    use App\Enums\FindingSeverity;
    use App\Filament\Resources\Findings\FindingResource;

    /** @var \App\Models\Asset|\App\Models\Repository $record */
    $record = $getRecord();
    $isRepo = $record instanceof \App\Models\Repository;

    $counts = $record->findings()
        ->reorder()
        ->issues()
        ->open()
        ->selectRaw('severity, count(*) as aggregate')
        ->groupBy('severity')
        ->pluck('aggregate', 'severity');

    $filterKey = $isRepo ? 'repository' : 'asset';
    $url = fn (?FindingSeverity $severity = null): string => FindingResource::getUrl('index', array_filter([
        'tab' => 'open',
        'filters' => array_filter([
            $filterKey => ['value' => $record->getKey()],
            'severity' => $severity ? ['values' => [$severity->value]] : null,
        ]),
    ]));
    $total = $counts->sum();
@endphp

<div class="hk-issues">
    <div class="hk-issues__total">
        <span class="hk-issues__number">{{ $total }}</span>
        <span class="hk-issues__label">open {{ \Illuminate\Support\Str::plural('issue', $total) }}</span>
    </div>

    <ul class="hk-issues__list">
        @foreach ([FindingSeverity::High, FindingSeverity::Medium, FindingSeverity::Low] as $severity)
            @php $n = (int) ($counts[$severity->value] ?? 0); @endphp
            <li>
                <a href="{{ $url($severity) }}" class="hk-issues__row hk-issues__row--{{ $severity->value }}" wire:navigate>
                    <span class="hk-issues__dot"></span>
                    <span class="hk-issues__name">{{ $severity->getLabel() }}</span>
                    <span class="hk-issues__count">{{ $n }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <a href="{{ $url() }}" class="hk-link" wire:navigate>
        Open in Findings
        <x-filament::icon icon="heroicon-m-arrow-right" class="hk-link__icon" />
    </a>
</div>
