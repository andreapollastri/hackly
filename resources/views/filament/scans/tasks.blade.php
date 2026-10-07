@php
    use App\Enums\ScanTaskStatus;

    /** @var \App\Models\Scan|\App\Models\RepoScan $scan */
    $scan = $getRecord();
    $tasks = $scan->tasks->sortBy('sort_order')->values();
@endphp

<ol class="hk-tasks">
    @forelse ($tasks as $task)
        @php
            $status = $task->status;
            $start = $task->started_at;
            $end = $task->finished_at;
            $seconds = $start && $end ? max(0, (int) $start->diffInSeconds($end)) : null;
            $duration = match (true) {
                $seconds === null => null,
                $seconds < 60 => $seconds.'s',
                default => \Carbon\CarbonInterval::seconds($seconds)->cascade()->forHumans(['short' => true, 'parts' => 2]),
            };
            $timing = match (true) {
                $status === ScanTaskStatus::Running && $start !== null => 'running for '.$start->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true),
                in_array($status, [ScanTaskStatus::Pending, ScanTaskStatus::Queued], true) && $task->scheduled_at?->isFuture() => 'starts '.$task->scheduled_at->diffForHumans(),
                in_array($status, [ScanTaskStatus::Pending, ScanTaskStatus::Queued], true) => 'waiting for a worker',
                default => $duration,
            };
            $findingsCount = $task->meta['findings_count'] ?? null;
        @endphp

        <li @class(['hk-task', 'hk-task--'.$status->value])>
            <span class="hk-task__status" title="{{ $status->getLabel() }}">
                <x-filament::icon :icon="$status->getIcon()" class="hk-task__status-icon" />
            </span>

            <div class="hk-task__body">
                <div class="hk-task__title">
                    <span class="hk-task__name">{{ $task->type->getLabel() }}</span>
                    <span class="hk-task__tool">{{ $task->type->toolName() }}</span>
                </div>
                <p class="hk-task__desc">{{ $task->type->getDescription() }}</p>

                @if (filled($task->error_message) && $status !== ScanTaskStatus::Completed)
                    <p @class(['hk-task__note', 'hk-task__note--danger' => $status === ScanTaskStatus::Failed])>
                        {{ $task->error_message }}
                    </p>
                @endif
            </div>

            <div class="hk-task__meta">
                @if ($findingsCount !== null && $status === ScanTaskStatus::Completed)
                    <span @class(['hk-chip', 'hk-chip--muted' => (int) $findingsCount === 0])>
                        {{ $findingsCount }} {{ \Illuminate\Support\Str::plural('result', (int) $findingsCount) }}
                    </span>
                @endif
                <span class="hk-task__state">{{ $status->getLabel() }}</span>
                @if ($timing)
                    <span class="hk-task__time">{{ $timing }}</span>
                @endif
            </div>
        </li>
    @empty
        <li class="hk-task hk-task--empty">No tasks were created for this scan.</li>
    @endforelse
</ol>
