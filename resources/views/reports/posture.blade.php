<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Hackly security posture</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 11px; line-height: 1.45; }
        .brand { font-size: 22px; font-weight: 700; color: #6d28d9; }
        .header { border-bottom: 3px solid #7c3aed; padding-bottom: 12px; margin-bottom: 14px; }
        .disclaimer { background: #fffbeb; border: 1px solid #fcd34d; padding: 8px 10px; margin: 12px 0 16px; }
        .score { font-size: 28px; font-weight: 700; color: #6d28d9; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0 16px; }
        th, td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; }
        th { background: #f8fafc; }
        h2 { font-size: 13px; color: #6d28d9; text-transform: uppercase; letter-spacing: 0.04em; }
    </style>
</head>
<body>
    <div class="header">
        <div class="brand">Hackly</div>
        <div>Security posture · {{ $report['generated_at'] }}</div>
    </div>
    <div class="disclaimer">{{ $report['disclaimer'] }}</div>
    @php($posture = $report['posture'])
    <p class="score">{{ $posture['grade'] ?? '—' }} <span style="font-size:14px;color:#64748b;">{{ $posture['score'] }}/100 · {{ $posture['subject']['label'] ?? 'All' }}</span></p>
    <p>{{ $posture['method'] }}</p>
    <table>
        <tr><th>Open high</th><td>{{ $posture['open']['high'] }}</td><th>Open medium</th><td>{{ $posture['open']['medium'] }}</td></tr>
        <tr><th>Open low</th><td>{{ $posture['open']['low'] }}</td><th>Acknowledged high</th><td>{{ $posture['acknowledged']['high'] }}</td></tr>
        <tr><th>Targets verified</th><td>{{ $posture['assets']['verified_targets'] }}/{{ $posture['assets']['targets'] }}</td><th>Repositories</th><td>{{ $posture['assets']['repositories'] }}</td></tr>
    </table>
    <h2>Framework scores</h2>
    <table>
        <thead><tr><th>Framework</th><th>Grade</th><th>Score</th><th>Gaps</th></tr></thead>
        <tbody>
            @foreach ($posture['compliance'] as $framework)
                <tr>
                    <td>{{ $framework['name'] }}</td>
                    <td>{{ $framework['grade'] ?? '—' }}</td>
                    <td>{{ $framework['score'] ?? '—' }}</td>
                    <td>{{ $framework['counts']['gap'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <h2>Highest open findings</h2>
    <table>
        <thead><tr><th>Severity</th><th>Title</th><th>Where</th></tr></thead>
        <tbody>
            @forelse ($report['findings'] as $finding)
                <tr>
                    <td>{{ strtoupper($finding['severity']) }}</td>
                    <td>{{ $finding['title'] }}</td>
                    <td>{{ $finding['subject'] }}</td>
                </tr>
            @empty
                <tr><td colspan="3">No open issues.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
