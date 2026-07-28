<div
    class="fi-scan-progress flex min-w-40 flex-col gap-1"
    wire:key="scan-progress-{{ $getRecord()->id }}-{{ $getState() }}"
>
    <div class="flex items-center justify-between gap-2 text-xs">
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ $getState() }}%</span>
        <span class="text-gray-500 dark:text-gray-400">
            {{ $getRecord()->finishedTasksCount() }}/{{ $getRecord()->totalTasksCount() }}
        </span>
    </div>
    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
        <div
            @class([
                'h-full rounded-full transition-all duration-500',
                'bg-emerald-500' => $getState() >= 100,
                'bg-amber-500' => $getState() > 0 && $getState() < 100,
                'bg-gray-400' => $getState() === 0,
            ])
            style="width: {{ max(0, min(100, (int) $getState())) }}%"
        ></div>
    </div>
</div>
