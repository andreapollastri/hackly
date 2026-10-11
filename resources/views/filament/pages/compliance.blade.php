<x-filament-panels::page>
    <div class="hk-compliance">
        <div class="hk-compliance__filter">
            <label for="compliance-subject">Scope</label>
            <select id="compliance-subject" wire:model.live="subject">
                <option value="">All</option>
                @foreach ($subjects as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            <span>A target, a repository, or both when they are linked. The score and the downloads follow this choice.</span>
        </div>

        <x-filament::section heading="Security posture" :description="$posture['method']">
            <div class="hk-compliance__score">
                <strong>{{ $posture['grade'] ?? '—' }}</strong>
                <span>{{ $posture['score'] }} / 100</span>
                <em>{{ $posture['subject']['label'] ?? 'All' }}</em>
            </div>
            <p>
                {{ $posture['open']['high'] }} open high ·
                {{ $posture['open']['medium'] }} medium ·
                {{ $posture['open']['low'] }} low ·
                {{ $posture['assets']['verified_targets'] }}/{{ $posture['assets']['targets'] }} targets verified ·
                {{ $posture['assets']['repositories'] }} repositories
            </p>
            @php($query = $subject !== '' ? ['subject' => $subject] : [])
            <div class="hk-compliance__links">
                <a href="{{ route('compliance.posture', ['format' => 'pdf'] + $query) }}">Posture PDF</a>
                <a href="{{ route('compliance.posture', ['format' => 'markdown'] + $query) }}">Posture Markdown</a>
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
                            <a href="{{ route('compliance.report', ['framework' => $framework['id'], 'format' => 'pdf'] + $query) }}">PDF</a>
                            <a href="{{ route('compliance.report', ['framework' => $framework['id'], 'format' => 'markdown'] + $query) }}">Markdown</a>
                        </div>
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
