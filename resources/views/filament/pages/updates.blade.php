<x-filament-panels::page>
    @php
        $when = fn (?string $time): ?string => $time ? \Illuminate\Support\Carbon::parse($time)->diffForHumans() : null;
        $status = $state['status'] ?? null;
    @endphp

    <div class="hk-health">
        @unless ($managed)
            <div class="hk-alert hk-alert--muted">
                <x-filament::icon icon="heroicon-o-information-circle" class="hk-alert__icon" />
                <span>
                    @if (! config('updates.enabled'))
                        Updates are off on this installation (a local environment, or <code>APP_UPDATES=false</code>): update it with git.
                    @else
                        Hackly updates itself when it is a git checkout, or a release of a deploy (<code>releases/</code>, <code>current</code>, <code>shared/</code>, as Cipi and Deployer make them). This installation is neither: update it the way it was installed.
                    @endif
                </span>
            </div>
        @elseif (! $updater->schedulerRunning())
            <div class="hk-alert hk-alert--warning">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="hk-alert__icon" />
                <span>
                    The scheduler has not run in the last few minutes. Updates asked for here, and the nightly one, start from it: add <code>* * * * * php artisan schedule:run</code> to the crontab of the user that runs Hackly.
                </span>
            </div>
        @endunless

        @if ($busy)
            {{-- polled until the update is done; during maintenance the requests get a 503, which is not an error here --}}
            <div wire:poll.3s="pollUpdate"
                 x-data="{ switching: false }"
                 x-init="$wire.$intercept('pollUpdate', ({ onError, onFailure, onSuccess }) => {
                     onError(({ preventDefault }) => { preventDefault(); switching = true });
                     onFailure(() => { switching = true });
                     onSuccess(() => { switching = false });
                 })"
                 class="hk-alert hk-alert--info" role="status">
                <x-filament::loading-indicator class="hk-alert__icon" />
                <span class="hk-updates__grow">
                    <span x-show="switching" x-cloak>Hackly is switching to the new build…</span>
                    <span x-show="! switching">{{ $updater->running() ? ($state['message'] ?? 'The update is running…') : 'The update starts within a minute…' }}</span>
                </span>
                <span class="hk-updates__hint">This page reloads itself when it is done.</span>
            </div>
        @endif

        <div class="hk-updates__grid">
            <x-filament::section heading="Installed" compact>
                <p class="hk-updates__value">{{ $updater->version() }}</p>
                <p class="hk-updates__meta">
                    Build <a href="{{ $updater->releaseUrl($installed) }}" target="_blank" rel="noopener" class="hk-inline-link hk-updates__build">{{ $installed }}</a>
                </p>
            </x-filament::section>

            <x-filament::section :heading="$channel === 'beta' ? 'Newest on main' : 'Newest release'" compact>
                <p class="hk-updates__value">
                    @if ($newest)
                        <a href="{{ $updater->releaseUrl($newest) }}" target="_blank" rel="noopener" class="hk-link">{{ $newest }}</a>
                    @else
                        <span class="hk-updates__meta">—</span>
                    @endif
                    @if ($available)
                        <x-filament::badge color="warning">update available</x-filament::badge>
                    @elseif (isset($state['checked_at']) && ! $waiting)
                        <x-filament::badge color="success">up to date</x-filament::badge>
                    @endif
                </p>
                <p class="hk-updates__meta">
                    {{ isset($state['checked_at']) ? 'Checked '.$when($state['checked_at']) : 'Not checked yet' }}
                </p>
                @if ($waiting)
                    <p class="hk-updates__note">{{ $waiting }}</p>
                @endif
            </x-filament::section>

            <x-filament::section heading="Automatic updates" compact>
                <div class="hk-updates__row">
                    <span>{{ $updater->automatic() ? 'Every night at '.config('updates.time') : 'Off' }}</span>
                    {{ $this->automaticAction }}
                </div>
                <p class="hk-updates__meta">The nightly run installs the newest build of the channel when there is one.</p>
            </x-filament::section>
        </div>

        <x-filament::section heading="Channel" compact>
            <x-slot name="description">
                @if ($channel === 'beta')
                    Beta tester mode: Hackly follows the main branch and installs every new commit on it, before it becomes a release.
                @else
                    Stable: Hackly installs the release tags (MAJOR.MINOR.PATCH) and nothing in between.
                @endif
            </x-slot>

            <x-slot name="afterHeader">
                <div class="hk-updates__row">
                    {{ ($this->channelAction)(['channel' => 'stable']) }}
                    {{ ($this->channelAction)(['channel' => 'beta']) }}
                </div>
            </x-slot>

            <p class="hk-updates__meta">Neither channel goes backwards: back on stable after beta, Hackly keeps its main build until a release contains it.</p>
        </x-filament::section>

        @if ($status !== null && $status !== 'running')
            <x-filament::section heading="Last update" compact>
                <x-slot name="afterHeader">
                    <div class="hk-updates__row">
                        @if ($state['triggered_automatically'] ?? false)
                            <span class="hk-updates__meta">nightly</span>
                        @endif
                        <x-filament::badge :color="match ($status) { 'ok', 'up-to-date' => 'success', 'failed' => 'danger', default => 'gray' }">
                            {{ match ($status) { 'ok' => 'updated', 'up-to-date' => 'up to date', 'failed' => 'failed', default => $status } }}
                        </x-filament::badge>
                        <span class="hk-updates__meta">{{ $when($state['finished_at'] ?? null) }}</span>
                    </div>
                </x-slot>

                <p class="hk-updates__message">{{ $state['message'] ?? '' }}</p>

                @if (! empty($state['log']))
                    <div x-data="{ open: {{ $status === 'failed' ? 'true' : 'false' }} }" class="hk-updates__log">
                        <button type="button" x-on:click="open = ! open" class="hk-link" x-text="open ? 'Hide the log' : 'Show the log'">Show the log</button>
                        <pre x-show="open" x-cloak>{{ implode("\n", $state['log']) }}</pre>
                    </div>
                @endif

                @if ($status === 'failed')
                    <p class="hk-updates__meta">The previous build and its database are back in place. The full log is in storage/app/updates/update.log.</p>
                @endif
            </x-filament::section>
        @endif

        <p class="hk-updates__meta">
            Each update backs up the database, installs the new build (in place for a git checkout, next to the running release for a deploy), installs its dependencies, migrates and checks that Hackly boots; if a step fails, the previous build and the database come back.
        </p>
    </div>
</x-filament-panels::page>
