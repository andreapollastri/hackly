<x-filament-panels::page>
    <div class="hk-compliance">
        <x-filament::section heading="Security posture" :description="$posture['method']">
            <div class="hk-compliance__score">
                <strong>{{ $posture['grade'] ?? '—' }}</strong>
                <span>{{ $posture['score'] }} / 100</span>
            </div>
            <p>
                {{ $posture['open']['high'] }} open high ·
                {{ $posture['open']['medium'] }} medium ·
                {{ $posture['open']['low'] }} low ·
                {{ $posture['assets']['verified_targets'] }}/{{ $posture['assets']['targets'] }} targets verified ·
                {{ $posture['assets']['repositories'] }} repositories
            </p>
            <div class="hk-compliance__links">
                <a href="{{ route('compliance.posture', ['format' => 'pdf']) }}">Posture PDF</a>
                <a href="{{ route('compliance.posture', ['format' => 'markdown']) }}">Posture Markdown</a>
            </div>
        </x-filament::section>

        <div class="hk-compliance__grid">
            @foreach ($posture['compliance'] as $framework)
                <x-filament::section :heading="$framework['name']" compact>
                    <div class="hk-compliance__card">
                        <strong>{{ $framework['grade'] ?? '—' }} @if ($framework['score'] !== null) · {{ $framework['score'] }} @endif</strong>
                        <p>{{ $framework['summary'] }}</p>
                        <p>
                            {{ $framework['counts']['met'] }} met ·
                            {{ $framework['counts']['partial'] }} partial ·
                            {{ $framework['counts']['gap'] }} gap ·
                            {{ $framework['counts']['not_assessed'] }} not assessed ·
                            {{ $framework['counts']['out_of_scope'] }} out of scope
                        </p>
                        <div class="hk-compliance__links">
                            <a href="{{ route('compliance.report', ['framework' => $framework['id'], 'format' => 'pdf']) }}">PDF</a>
                            <a href="{{ route('compliance.report', ['framework' => $framework['id'], 'format' => 'markdown']) }}">Markdown</a>
                        </div>
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
