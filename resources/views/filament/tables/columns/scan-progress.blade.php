@php
    use App\Enums\ScanTaskStatus;

    $scan = $getRecord();
    $tasks = $scan->relationLoaded('tasks')
        ? $scan->tasks->sortBy('sort_order')->values()
        : $scan->tasks()->orderBy('sort_order')->get();

    $total = $tasks->count();
    $done = $tasks->filter(fn ($task) => $task->status->isFinished())->count();
    $key = $tasks->map(fn ($task) => $task->status->value)->implode('-');
@endphp

<div class="hk-progress" wire:key="scan-progress-{{ $scan->id }}-{{ $key }}">
    @if ($total === 0)
        <span class="hk-muted">—</span>
    @else
        <div class="hk-progress__bar" role="img" aria-label="{{ $done }} of {{ $total }} tasks finished">
            @foreach ($tasks as $task)
                @php
                    $tone = match ($task->status) {
                        ScanTaskStatus::Completed => 'done',
                        ScanTaskStatus::Failed => 'failed',
                        ScanTaskStatus::Skipped => 'skipped',
                        ScanTaskStatus::Running => 'running',
                        default => 'idle',
                    };
                @endphp
                <span
                    class="hk-progress__seg hk-progress__seg--{{ $tone }}"
                    title="{{ $task->type->getLabel() }} · {{ $task->status->getLabel() }}"
                ></span>
            @endforeach
        </div>
        <span class="hk-progress__label">{{ $done }}/{{ $total }}</span>
    @endif
</div>
