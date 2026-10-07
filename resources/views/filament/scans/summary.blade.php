@php
    /** @var \App\Models\Scan|\App\Models\RepoScan $scan */
    $scan = $getRecord();
    $scan->loadMissing('tasks');
    $summary = $scan->findingsSeveritySummary();
    $active = $scan->isActive();
    $progress = $scan->progressPercent();
    $isRepo = $scan instanceof \App\Models\RepoScan;
    $status = $scan->status;
@endphp

<div class="hk-scan-summary" @if ($active) wire:poll.5s @endif>
    <div class="hk-scan-summary__head">
        <span class="hk-status hk-status--{{ $status->getColor() }} @if ($active) is-live @endif">
            <x-filament::icon :icon="$status->getIcon()" class="hk-status__icon" />
            {{ $status->getLabel() }}
        </span>

        <div class="hk-scan-summary__meter">
            <div class="hk-meter hk-meter--{{ $status->getColor() }}" role="progressbar" aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100">
                <span style="width: {{ $progress }}%"></span>
            </div>
            <span class="hk-scan-summary__meter-label">
                {{ $scan->finishedTasksCount() }} of {{ $scan->totalTasksCount() }} tasks finished · {{ $progress }}%
            </span>
        </div>
    </div>

    <dl class="hk-scan-summary__stats">
        <div class="hk-kv">
            <dt>Profile</dt>
            <dd>
                <span class="hk-chip">
                    <x-filament::icon :icon="$scan->profile->getIcon()" class="hk-chip__icon" />
                    {{ $scan->profile->getLabel() }}
                </span>
            </dd>
        </div>
        <div class="hk-kv">
            <dt>{{ $active ? 'Running for' : 'Duration' }}</dt>
            <dd>{{ $scan->durationForHumans() ?? '—' }}</dd>
        </div>
        <div class="hk-kv">
            <dt>Started</dt>
            <dd title="{{ ($scan->started_at ?? $scan->created_at)?->toDayDateTimeString() }}">
                {{ ($scan->started_at ?? $scan->created_at)?->diffForHumans() ?? '—' }}
            </dd>
        </div>
        @if ($isRepo)
            <div class="hk-kv">
                <dt>Commit</dt>
                <dd class="hk-mono" title="{{ $scan->commit_sha }}">{{ $scan->commit_sha ? substr($scan->commit_sha, 0, 10) : '—' }}</dd>
            </div>
        @else
            <div class="hk-kv">
                <dt>Requested by</dt>
                <dd>{{ $scan->requester?->name ?? 'CLI' }}</dd>
            </div>
        @endif
        <div class="hk-kv hk-kv--high">
            <dt>High</dt>
            <dd>{{ $summary['high'] }}</dd>
        </div>
        <div class="hk-kv hk-kv--medium">
            <dt>Medium</dt>
            <dd>{{ $summary['medium'] }}</dd>
        </div>
        <div class="hk-kv hk-kv--low">
            <dt>Low</dt>
            <dd>{{ $summary['low'] }}</dd>
        </div>
    </dl>

    @if (filled($scan->error_message))
        <div class="hk-alert hk-alert--danger">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="hk-alert__icon" />
            <span>{{ $scan->error_message }}</span>
        </div>
    @endif

    @if ($active && $scan->tasks->every(fn ($task) => in_array($task->status, [\App\Enums\ScanTaskStatus::Pending, \App\Enums\ScanTaskStatus::Queued], true)) && ($scan->created_at?->lt(now()->subMinutes(2)) ?? false))
        <div class="hk-alert hk-alert--warning">
            <x-filament::icon icon="heroicon-o-clock" class="hk-alert__icon" />
            <span>No task has started yet. Is a queue worker running? Start one with <code>php artisan queue:work</code>.</span>
        </div>
    @endif
</div>
