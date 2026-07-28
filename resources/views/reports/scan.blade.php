<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Hackly Scan Report</title>
    <style>
        @page { margin: 28px 32px; }
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #0f172a;
            font-size: 11px;
            line-height: 1.45;
        }
        .header {
            border-bottom: 3px solid #0d9488;
            padding-bottom: 14px;
            margin-bottom: 18px;
        }
        .brand {
            font-size: 22px;
            font-weight: 700;
            color: #0f766e;
            letter-spacing: -0.02em;
        }
        .subtitle {
            color: #64748b;
            margin-top: 2px;
        }
        .meta {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }
        .meta td {
            padding: 6px 8px;
            vertical-align: top;
            border: 1px solid #e2e8f0;
        }
        .meta td.label {
            width: 22%;
            background: #f8fafc;
            color: #475569;
            font-weight: 700;
        }
        .section-title {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #0f766e;
            margin: 18px 0 10px;
        }
        .summary {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        .summary td {
            width: 33.33%;
            text-align: center;
            padding: 14px 8px;
            border: 1px solid #e2e8f0;
        }
        .summary .count {
            font-size: 26px;
            font-weight: 700;
            line-height: 1.1;
        }
        .summary .label {
            margin-top: 4px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.06em;
        }
        .bar-wrap {
            margin: 12px 0 18px;
            background: #f1f5f9;
            border-radius: 6px;
            overflow: hidden;
            height: 18px;
        }
        .bar {
            float: left;
            height: 18px;
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            color: #fff;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.04em;
        }
        .findings {
            width: 100%;
            border-collapse: collapse;
        }
        .findings th {
            text-align: left;
            background: #0f766e;
            color: #fff;
            padding: 8px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .findings td {
            padding: 8px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }
        .findings tr:nth-child(even) td {
            background: #f8fafc;
        }
        .finding-title {
            font-weight: 700;
            color: #0f172a;
        }
        .finding-desc {
            color: #334155;
            margin-top: 4px;
            font-size: 10px;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .finding-evidence {
            margin-top: 6px;
            padding: 6px 8px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            font-size: 9px;
            color: #0f172a;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-family: DejaVu Sans Mono, monospace;
            line-height: 1.4;
        }
        .finding-evidence-label {
            font-family: DejaVu Sans, sans-serif;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 8px;
            margin-bottom: 3px;
        }
        .footer {
            margin-top: 24px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
            color: #94a3b8;
            font-size: 9px;
        }
        .empty {
            padding: 20px;
            text-align: center;
            color: #64748b;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
        }
        .tasks {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        .tasks th, .tasks td {
            padding: 6px 8px;
            border: 1px solid #e2e8f0;
            text-align: left;
        }
        .tasks th {
            background: #f8fafc;
            color: #475569;
            font-size: 10px;
            text-transform: uppercase;
        }
    </style>
</head>
<body>
    @php
        $total = max(1, array_sum($summary));
        $highPct = round(($summary['high'] / $total) * 100, 1);
        $mediumPct = round(($summary['medium'] / $total) * 100, 1);
        $lowPct = round(($summary['low'] / $total) * 100, 1);
        if (array_sum($summary) === 0) {
            $highPct = $mediumPct = $lowPct = 0;
        }
    @endphp

    <div class="header">
        <div class="brand">Hackly</div>
        <div class="subtitle">Authorized security scan report</div>
    </div>

    <table class="meta">
        <tr>
            <td class="label">Target</td>
            <td>{{ $scan->asset?->value ?? '—' }}</td>
            <td class="label">Scan ID</td>
            <td>{{ $scan->id }}</td>
        </tr>
        <tr>
            <td class="label">Profile</td>
            <td>{{ strtoupper($scan->profile->value) }}</td>
            <td class="label">Status</td>
            <td>{{ strtoupper($scan->status->value) }}</td>
        </tr>
        <tr>
            <td class="label">Started</td>
            <td>{{ optional($scan->started_at ?? $scan->created_at)->format('Y-m-d H:i') }}</td>
            <td class="label">Finished</td>
            <td>{{ optional($scan->finished_at)->format('Y-m-d H:i') ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Requested by</td>
            <td>{{ $scan->requester?->email ?? '—' }}</td>
            <td class="label">Findings</td>
            <td>{{ $findings->count() }}</td>
        </tr>
    </table>

    <div class="section-title">Severity overview</div>
    <table class="summary">
        <tr>
            <td>
                <div class="count" style="color:#dc2626;">{{ $summary['high'] }}</div>
                <div class="label" style="color:#dc2626;">HIGH</div>
            </td>
            <td>
                <div class="count" style="color:#ea580c;">{{ $summary['medium'] }}</div>
                <div class="label" style="color:#ea580c;">MEDIUM</div>
            </td>
            <td>
                <div class="count" style="color:#16a34a;">{{ $summary['low'] }}</div>
                <div class="label" style="color:#16a34a;">LOW</div>
            </td>
        </tr>
    </table>

    @if (array_sum($summary) > 0)
        <div class="bar-wrap">
            <div class="bar" style="width:{{ $highPct }}%; background:#dc2626;"></div>
            <div class="bar" style="width:{{ $mediumPct }}%; background:#ea580c;"></div>
            <div class="bar" style="width:{{ $lowPct }}%; background:#16a34a;"></div>
        </div>
    @endif

    <div class="section-title">Scan tasks</div>
    <table class="tasks">
        <thead>
            <tr>
                <th>Type</th>
                <th>Status</th>
                <th>Started</th>
                <th>Finished</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($scan->tasks as $task)
                <tr>
                    <td>{{ $task->type->value }}</td>
                    <td>{{ $task->status->value }}</td>
                    <td>{{ optional($task->started_at)->format('Y-m-d H:i') ?? '—' }}</td>
                    <td>{{ optional($task->finished_at)->format('Y-m-d H:i') ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">No tasks recorded.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="section-title">Findings</div>
    @if ($findings->isEmpty())
        <div class="empty">No findings were reported for this scan.</div>
    @else
        <table class="findings">
            <thead>
                <tr>
                    <th style="width:70px;">Severity</th>
                    <th>Finding</th>
                    <th style="width:70px;">Source</th>
                    <th style="width:90px;">Category</th>
                    <th style="width:90px;">CVE</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($findings as $finding)
                    @php($evidenceLines = $finding->evidenceDetailLines())
                    <tr>
                        <td>
                            <span class="badge" style="background:{{ $finding->severity->hex() }};">
                                {{ $finding->severity->label() }}
                            </span>
                        </td>
                        <td>
                            <div class="finding-title">{{ $finding->title }}</div>
                            @if (filled($finding->description))
                                <div class="finding-desc">{{ $finding->description }}</div>
                            @endif
                            @if ($evidenceLines !== [])
                                <div class="finding-evidence">
                                    <div class="finding-evidence-label">Detail</div>
                                    {{ implode("\n", $evidenceLines) }}
                                </div>
                            @endif
                        </td>
                        <td>{{ $finding->source }}</td>
                        <td>{{ $finding->category ?? '—' }}</td>
                        <td>{{ $finding->cve ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">
        Generated by Hackly on {{ $generatedAt->format('Y-m-d H:i:s') }} · Authorized assessment only
    </div>
</body>
</html>
