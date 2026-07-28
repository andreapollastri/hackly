@php
    use App\Enums\ScanTaskStatus;
    use App\Models\ScanTask;

    $scan = $getRecord();
    $tasks = $scan->relationLoaded('tasks')
        ? $scan->tasks->sortBy('sort_order')->values()
        : $scan->tasks()->orderBy('sort_order')->get();

    $dotKey = $tasks->map(fn (ScanTask $task) => $task->status->value)->implode('-');

    $styles = [
        'idle' => 'width:10px;height:10px;border-radius:9999px;box-sizing:border-box;border:2px solid #94a3b8;background:transparent;',
        'running' => 'width:10px;height:10px;border-radius:9999px;box-sizing:border-box;border:2px solid #f59e0b;background:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,0.22);',
        'done' => 'width:10px;height:10px;border-radius:9999px;box-sizing:border-box;border:2px solid #10b981;background:#10b981;',
        'failed' => 'width:10px;height:10px;border-radius:9999px;box-sizing:border-box;border:2px solid #f43f5e;background:#f43f5e;',
    ];
@endphp

<div
    style="display:flex;align-items:center;gap:6px;"
    wire:key="scan-progress-{{ $scan->id }}-{{ $dotKey }}"
    title="{{ $tasks->map(fn (ScanTask $task) => $task->type->value.': '.$task->status->value)->implode(' · ') }}"
>
    @forelse ($tasks as $task)
        @php
            $tone = match ($task->status) {
                ScanTaskStatus::Completed => 'done',
                ScanTaskStatus::Failed => 'failed',
                ScanTaskStatus::Running => 'running',
                default => 'idle',
            };
        @endphp
        <span
            style="display:inline-block;{{ $styles[$tone] }}"
            title="{{ $task->type->value }} · {{ $task->status->value }}"
        ></span>
    @empty
        <span style="font-size:12px;color:#94a3b8;">—</span>
    @endforelse
</div>
