<x-mail::message>
# {{ $report['subject'] }}

Week of {{ $report['period']['label'] }}. Posture **{{ $report['posture']['grade'] }}** ({{ $report['posture']['score'] }}/100).

**Open now:** {{ $report['open']['high'] }} high · {{ $report['open']['medium'] }} medium · {{ $report['open']['low'] }} low

**Acknowledged:** {{ $report['acknowledged']['high'] }} high · {{ $report['acknowledged']['medium'] }} medium · {{ $report['acknowledged']['low'] }} low

**New this week:** {{ $report['new_count'] }} · **Scans completed:** {{ $report['scans']['completed'] }}

## Situations

@foreach ($report['situations'] as $situation)
- **{{ $situation['title'] }}:** {{ $situation['detail'] }}
@endforeach

@if ($report['categories'] !== [])
## Open issues by category

@foreach ($report['categories'] as $category)
- {{ $category['category'] }}: {{ $category['count'] }}
@endforeach
@endif

## New this week

@forelse ($report['new'] as $row)
- [{{ $row['severity'] }}] {{ $row['title'] }} — {{ $row['subject'] }}
@empty
- None
@endforelse

## Marked false positive this week

@forelse ($report['triage']['false_positives'] as $row)
- [{{ $row['severity'] }}] {{ $row['title'] }} — {{ $row['subject'] }}@if (filled($row['note'] ?? null)) — note: {{ $row['note'] }}@endif
@empty
- None
@endforelse

## Marked fixed this week

@forelse ($report['triage']['fixed'] as $row)
- [{{ $row['severity'] }}] {{ $row['title'] }} — {{ $row['subject'] }}
@empty
- None
@endforelse

## Failed scans this week

@forelse ($report['scans']['failed'] as $scan)
- {{ $scan['subject'] }} ({{ $scan['kind'] }}): {{ $scan['error'] }}
@empty
- None
@endforelse

## Still open

@forelse ($report['open_findings'] as $row)
- [{{ $row['severity'] }}] {{ $row['title'] }} — {{ $row['subject'] }}
@empty
- None
@endforelse

<x-mail::button :url="$report['findings_url']">
Open findings
</x-mail::button>

This note is the Sunday digest for the Hackly workspace. It lists what the scanners found and what changed in the last seven days.
</x-mail::message>
