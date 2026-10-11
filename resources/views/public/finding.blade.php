<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $finding->title }} — Hackly</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: ui-sans-serif, system-ui, sans-serif; background: #f6f7fb; color: #151820; }
        header, main { width: min(920px, calc(100% - 2rem)); margin: 0 auto; }
        header { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; padding: 1.25rem 0; }
        .brand { font-weight: 700; letter-spacing: -0.03em; }
        .brand span { color: #7c3aed; }
        .muted { color: #64748b; font-size: 0.875rem; }
        .actions { margin-left: auto; display: flex; flex-wrap: wrap; gap: 0.5rem; }
        button, .linkish { border: 1px solid #e2e8f0; background: white; color: #151820; border-radius: 999px; padding: 0.4rem 0.8rem; font: inherit; font-size: 0.8125rem; font-weight: 600; cursor: pointer; text-decoration: none; }
        button:hover, .linkish:hover { border-color: #c4b5fd; }
        article { background: white; border: 1px solid #e6e8ef; border-radius: 16px; padding: 1.25rem 1.4rem 1.5rem; }
        .meta { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 0.8rem; }
        .pill { font-size: 0.75rem; font-weight: 700; letter-spacing: 0.02em; text-transform: uppercase; border-radius: 999px; padding: 0.15rem 0.5rem; background: #f1f5f9; }
        .pill.high { background: #fee2e2; color: #b91c1c; }
        .pill.medium { background: #fef3c7; color: #b45309; }
        .pill.low { background: #e0f2fe; color: #0369a1; }
        h1 { font-size: 1.35rem; line-height: 1.35; margin: 0 0 0.75rem; }
        h2 { font-size: 0.95rem; margin: 1.25rem 0 0.4rem; }
        p, li { line-height: 1.55; }
        pre { white-space: pre-wrap; word-break: break-word; background: #151820; color: #f8fafc; border-radius: 12px; padding: 0.9rem 1rem; font-size: 0.8125rem; overflow: auto; }
        footer { text-align: center; color: #94a3b8; font-size: 0.75rem; padding: 1.5rem 0 2rem; }
        textarea { position: absolute; left: -9999px; }
    </style>
</head>
<body>
    <header>
        <div class="brand">Hack<span>ly</span></div>
        <div class="muted">Shared finding · {{ $finding->subjectName() }}</div>
        <div class="actions">
            <button type="button" data-copy="finding-md">Copy Markdown</button>
            <button type="button" data-copy="finding-prompt">Copy AI prompt</button>
            <a class="linkish" href="{{ request()->fullUrlWithQuery(['format' => 'markdown']) }}">Markdown</a>
        </div>
    </header>
    <main>
        <article>
            <div class="meta">
                <span class="pill {{ $finding->severity?->value }}">{{ $finding->severity?->getLabel() }}</span>
                <span class="pill">{{ $finding->status?->getLabel() }}</span>
                <span class="pill">{{ $finding->source }}</span>
                @if ($finding->category)
                    <span class="pill">{{ \App\Models\Finding::categoryLabel($finding->category) }}</span>
                @endif
            </div>
            <h1>{{ $finding->title }}</h1>
            @if (filled($finding->description))
                <p>{{ $finding->description }}</p>
            @endif
            @if (filled($finding->cve))
                <p><strong>CVE:</strong> {{ $finding->cve }}</p>
            @endif
            @if ($finding->reachability)
                <p><strong>Reachability:</strong> {{ $finding->reachability->value }}</p>
            @endif
            @if (filled($finding->false_positive_note))
                <h2>False positive note</h2>
                <p>{{ $finding->false_positive_note }}</p>
            @endif
            @php($lines = $finding->evidenceDetailLines())
            @if ($lines !== [])
                <h2>Evidence</h2>
                <pre>{{ implode("\n", $lines) }}</pre>
            @endif
        </article>
    </main>
    <footer>Read-only share from Hackly. The link stops working when the workspace unshares this finding.</footer>
    <textarea id="finding-md" readonly>{{ $markdown }}</textarea>
    <textarea id="finding-prompt" readonly>{{ $prompt }}</textarea>
    <script>
        async function hacklyCopy(text) {
            try {
                await navigator.clipboard.writeText(text);
            } catch (e) {
                const area = document.createElement('textarea');
                area.value = text;
                document.body.appendChild(area);
                area.select();
                document.execCommand('copy');
                area.remove();
            }
        }
        document.querySelectorAll('[data-copy]').forEach((button) => {
            button.addEventListener('click', async () => {
                const source = document.getElementById(button.dataset.copy);
                await hacklyCopy(source ? source.value : '');
                const previous = button.textContent;
                button.textContent = 'Copied';
                setTimeout(() => { button.textContent = previous; }, 1600);
            });
        });
    </script>
</body>
</html>
