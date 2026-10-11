<?php

namespace App\Http\Resources;

use App\Models\ScanTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScanTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ScanTask $task */
        $task = $this->resource;

        return [
            'id' => $task->id,
            'type' => $task->type?->value,
            'label' => $task->type?->getLabel(),
            'status' => $task->status?->value,
            'attempts' => $task->attempts,
            'sort_order' => $task->sort_order,
            'scheduled_at' => $task->scheduled_at?->toIso8601String(),
            'started_at' => $task->started_at?->toIso8601String(),
            'finished_at' => $task->finished_at?->toIso8601String(),
            'error_message' => $task->error_message,
        ];
    }
}
