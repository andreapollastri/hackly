<?php

namespace App\Filament\Resources\Findings\Pages;

use App\Enums\FindingStatus;
use App\Filament\Resources\Findings\FindingResource;
use App\Models\Finding;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListFindings extends ListRecords
{
    protected static string $resource = FindingResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Every issue reported by target and repository scans. Triage them to keep the list actionable.';
    }

    public function getTabs(): array
    {
        $counts = Finding::query()
            ->issues()
            ->reorder()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $tabs = [];

        foreach ([FindingStatus::Open, FindingStatus::Ack, FindingStatus::Fixed, FindingStatus::FalsePositive] as $status) {
            $tabs[$status->value] = Tab::make($status->getLabel())
                ->icon($status->getIcon())
                ->badge((int) ($counts[$status->value] ?? 0) ?: null)
                ->badgeColor($status === FindingStatus::Open ? 'danger' : 'gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', $status));
        }

        $tabs['all'] = Tab::make('All');

        return $tabs;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return FindingStatus::Open->value;
    }
}
