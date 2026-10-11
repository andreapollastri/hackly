# Hackly security posture

**Generated:** {{ $report['generated_at'] }}

> {{ $report['disclaimer'] }}

@php($posture = $report['posture'])
**Grade:** {{ $posture['grade'] ?? '—' }} ({{ $posture['score'] }}/100)
**Scope:** {{ $posture['subject']['label'] ?? 'All' }}

{{ $posture['method'] }}

| | |
| --- | --- |
| Open high / medium / low | {{ $posture['open']['high'] }} / {{ $posture['open']['medium'] }} / {{ $posture['open']['low'] }} |
| Acknowledged high | {{ $posture['acknowledged']['high'] }} |
| Targets verified | {{ $posture['assets']['verified_targets'] }}/{{ $posture['assets']['targets'] }} |
| Repositories | {{ $posture['assets']['repositories'] }} |
| Last completed scan | {{ $posture['scans']['last_completed_at'] ?? '—' }} |

## Framework scores

| Framework | Grade | Score | Gaps |
| --- | --- | --- | --- |
@foreach ($posture['compliance'] as $framework)
| {{ $framework['name'] }} | {{ $framework['grade'] ?? '—' }} | {{ $framework['score'] ?? '—' }} | {{ $framework['counts']['gap'] }} |
@endforeach

## Highest open findings

@forelse ($report['findings'] as $finding)
- **{{ strtoupper($finding['severity']) }}** {{ $finding['title'] }} — {{ $finding['subject'] }}
@empty
None.
@endforelse
