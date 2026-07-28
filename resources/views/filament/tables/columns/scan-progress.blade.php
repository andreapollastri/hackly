@php
    use App\Enums\ScanTaskStatus;
    use App\Models\ScanTask;

    $scan = $getRecord();
    $tasks = $scan->relationLoaded('tasks')
        ? $scan->tasks->sortBy('sort_order')->values()
        : $scan->tasks()->orderBy('sort_order')->get();

    $dotKey = $tasks->map(fn (ScanTask $task) => $task->status->value)->implode('-');
@endphp

<div
    class="fi-scan-progress flex items-center gap-1.5"
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
            @class([
                'inline-block size-2.5 rounded-full transition-colors duration-300',
                'border-2 border-gray-300 bg-transparent dark:border-gray-600' => $tone === 'idle',
                'bg-amber-500 shadow-[0_0_0_2px_rgba(245,158,11,0.25)]' => $tone === 'running',
                'bg-emerald-500' => $tone === 'done',
                'bg-rose-500' => $tone === 'failed',
            ])
            title="{{ $task->type->value }} · {{ $task->status->value }}"
        ></span>
    @empty
        <span class="text-xs text-gray-400 dark:text-gray-500">—</span>
    @endforelse
</div>
