# {{ $report['name'] }} — Hackly evidence pack

{{ $report['citation'] }}

> {{ $report['disclaimer'] }}

**Score:** {{ $report['grade'] ?? '—' }} @if ($report['score'] !== null)({{ $report['score'] }}/100) @endif
**Generated:** {{ $report['generated_at'] }}

{{ $report['summary'] }}

## Scope

| | |
| --- | --- |
| Targets verified | {{ $report['scope']['verified_targets'] }}/{{ $report['scope']['targets'] }} |
| Repositories | {{ $report['scope']['repositories'] }} |
| Open issues | {{ $report['scope']['open_high'] }} high · {{ $report['scope']['open_medium'] }} medium · {{ $report['scope']['open_low'] }} low |
| Last completed scan | {{ $report['scope']['last_completed_scan_at'] ?? '—' }} |

## Controls

| Ref | Control | Status | Open findings |
| --- | --- | --- | --- |
@foreach ($report['controls'] as $control)
| {{ $control['reference'] }} | {{ $control['title'] }} | {{ str_replace('_', ' ', $control['status']) }} | {{ $control['open_findings'] }} |
@endforeach

@foreach ($report['controls'] as $control)
@if ($control['findings'] !== [])
### {{ $control['reference'] }} {{ $control['title'] }}

{{ $control['requirement'] }}

@foreach ($control['findings'] as $finding)
- **{{ strtoupper($finding['severity']) }}** {{ $finding['title'] }} — {{ $finding['subject'] }}
@endforeach
@if ($control['more_findings'] > 0)

+ {{ $control['more_findings'] }} more
@endif

@elseif ($control['note'])
### {{ $control['reference'] }} {{ $control['title'] }}

{{ $control['note'] }}

@endif
@endforeach
