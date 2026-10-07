<x-filament-panels::page>
    <div class="hk-health">
        <div @class(['hk-alert', 'hk-alert--success' => $missing === 0, 'hk-alert--warning' => $missing > 0])>
            <x-filament::icon :icon="$missing === 0 ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'" class="hk-alert__icon" />
            <span>
                @if ($missing === 0)
                    Every configured scanner was found. All scan profiles can run in full.
                @else
                    <strong>{{ $missing }} {{ \Illuminate\Support\Str::plural('binary', $missing) }} not found.</strong>
                    Tasks that need them are skipped (the rest of the scan still runs). On Ubuntu run
                    <code>sudo bash scripts/install-scanner-binaries-ubuntu.sh</code> and
                    <code>sudo bash scripts/install-repo-scanners.sh</code>, then <code>php artisan hackly:check-binaries</code>.
                @endif
            </span>
        </div>

        <div class="hk-health__grid">
            @foreach (['Target scanners (DAST / ASM)' => $targetBinaries, 'Repository scanners (SAST / SCA)' => $repoBinaries] as $heading => $rows)
                <x-filament::section :heading="$heading" compact>
                    <ul class="hk-bin-list">
                        @foreach ($rows as $bin)
                            <li class="hk-bin">
                                <span @class(['hk-dot', 'hk-dot--ok' => $bin['available'], 'hk-dot--bad' => ! $bin['available']])></span>
                                <div class="hk-bin__body">
                                    <div class="hk-bin__head">
                                        <span class="hk-bin__name">{{ $bin['name'] }}</span>
                                        <span class="hk-bin__used">{{ implode(' · ', $bin['used_by']) }}</span>
                                    </div>
                                    @if ($bin['available'])
                                        <code class="hk-bin__path">{{ $bin['resolved'] }}</code>
                                    @else
                                        <p class="hk-bin__hint">Not found{{ $bin['path'] !== $bin['name'] ? ' at '.$bin['path'] : '' }}{{ $bin['hint'] ? ' — '.$bin['hint'] : '' }}</p>
                                    @endif
                                </div>
                                <span @class(['hk-bin__state', 'is-ok' => $bin['available']])>{{ $bin['available'] ? 'Ready' : 'Missing' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @endforeach
        </div>

        <div class="hk-health__grid">
            <x-filament::section heading="Queue" description="Scans only progress while a worker runs: php artisan queue:work" compact>
                <dl class="hk-facts">
                    <div><dt>Connection</dt><dd><code>{{ $queue['connection'] }}</code></dd></div>
                    <div><dt>Jobs waiting</dt><dd>{{ $queue['pending'] ?? 'n/a' }}</dd></div>
                    <div>
                        <dt>Waiting &gt; 5 min</dt>
                        <dd @class(['hk-text-warn' => $queue['stalled'] > 0])>{{ $queue['stalled'] }}{{ $queue['stalled'] > 0 ? ' — is a worker running?' : '' }}</dd>
                    </div>
                    <div><dt>Failed jobs</dt><dd @class(['hk-text-danger' => ($queue['failed'] ?? 0) > 0])>{{ $queue['failed'] ?? 'n/a' }}</dd></div>
                </dl>
            </x-filament::section>

            <x-filament::section heading="Safety limits" description="Configured in config/hackly.php / .env" compact>
                <dl class="hk-facts">
                    @foreach ($safety as $row)
                        <div>
                            <dt>{{ $row['label'] }}</dt>
                            <dd>{{ $row['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
