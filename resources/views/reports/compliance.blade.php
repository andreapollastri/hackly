<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report['name'] }} — Hackly</title>
    <style>
        @page { margin: 28px 32px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 11px; line-height: 1.45; }
        .brand { font-size: 22px; font-weight: 700; color: #6d28d9; }
        .subtitle { color: #64748b; margin-top: 2px; }
        .header { border-bottom: 3px solid #7c3aed; padding-bottom: 12px; margin-bottom: 14px; }
        .disclaimer { background: #fffbeb; border: 1px solid #fcd34d; padding: 8px 10px; margin: 12px 0 16px; }
        .score { font-size: 28px; font-weight: 700; color: #6d28d9; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #e2e8f0; padding: 6px 8px; vertical-align: top; text-align: left; }
        th { background: #f8fafc; color: #475569; }
        .met { color: #166534; font-weight: 700; }
        .partial { color: #b45309; font-weight: 700; }
        .gap { color: #b91c1c; font-weight: 700; }
        .muted { color: #64748b; }
        h2 { font-size: 13px; color: #6d28d9; text-transform: uppercase; letter-spacing: 0.04em; margin: 16px 0 8px; }
        ul { margin: 4px 0 0 16px; padding: 0; }
    </style>
</head>
<body>
    <div class="header">
        <div class="brand">Hackly</div>
        <div class="subtitle">{{ $report['name'] }} evidence pack · {{ $report['citation'] }}</div>
    </div>
    <div class="disclaimer">{{ $report['disclaimer'] }}</div>
    <p class="score">{{ $report['grade'] ?? '—' }} @if ($report['score'] !== null)<span style="font-size:14px;color:#64748b;">{{ $report['score'] }}/100</span>@endif</p>
    <p>{{ $report['summary'] }}</p>
    <table>
        <tr>
            <th>Generated</th><td>{{ $report['generated_at'] }}</td>
            <th>Scope</th><td>{{ $report['scope']['subject']['label'] ?? 'All' }}</td>
        </tr>
        <tr>
            <th>Targets</th><td>{{ $report['scope']['verified_targets'] }}/{{ $report['scope']['targets'] }} verified</td>
            <th>Repositories</th><td>{{ $report['scope']['repositories'] }}</td>
        </tr>
        <tr>
            <th>Open issues</th><td colspan="3">{{ $report['scope']['open_high'] }} high · {{ $report['scope']['open_medium'] }} medium · {{ $report['scope']['open_low'] }} low</td>
        </tr>
    </table>
    <h2>Controls</h2>
    <table>
        <thead>
            <tr><th>Ref</th><th>Control</th><th>Status</th><th>Evidence</th></tr>
        </thead>
        <tbody>
            @foreach ($report['controls'] as $control)
                <tr>
                    <td>{{ $control['reference'] }}</td>
                    <td>
                        <strong>{{ $control['title'] }}</strong><br>
                        <span class="muted">{{ $control['requirement'] }}</span>
                        @if ($control['note'])<br><span class="muted">{{ $control['note'] }}</span>@endif
                    </td>
                    <td class="{{ $control['status'] }}">{{ str_replace('_', ' ', $control['status']) }}</td>
                    <td>
                        @if ($control['findings'] === [])
                            —
                        @else
                            <ul>
                                @foreach ($control['findings'] as $finding)
                                    <li>{{ strtoupper($finding['severity']) }} · {{ $finding['title'] }} ({{ $finding['subject'] }})</li>
                                @endforeach
                            </ul>
                            @if ($control['more_findings'] > 0)
                                <span class="muted">+ {{ $control['more_findings'] }} more</span>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
