<?php

namespace App\Filament\Widgets;

use App\Models\Finding;
use Filament\Widgets\ChartWidget;

class IssuesBySourceChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Open issues by scanner';

    protected ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 1];

    private const PALETTE = ['#8b5cf6', '#0ea5e9', '#f59e0b', '#ef4444', '#10b981', '#ec4899', '#94a3b8'];

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $bySource = Finding::query()
            ->issues()
            ->open()
            ->reorder()
            ->selectRaw('source, count(*) as aggregate')
            ->groupBy('source')
            ->orderByDesc('aggregate')
            ->pluck('aggregate', 'source');

        $top = $bySource->take(6);
        $other = $bySource->skip(6)->sum();

        if ($other > 0) {
            $top->put('other', $other);
        }

        return [
            'labels' => $top->keys()->all(),
            'datasets' => [[
                'data' => $top->values()->map(fn ($v) => (int) $v)->all(),
                'backgroundColor' => array_slice(self::PALETTE, 0, $top->count()),
                'borderWidth' => 0,
                'hoverOffset' => 6,
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'cutout' => '68%',
            'plugins' => [
                'legend' => ['position' => 'bottom', 'labels' => ['usePointStyle' => true, 'boxWidth' => 8]],
            ],
            'scales' => [
                'x' => ['display' => false],
                'y' => ['display' => false],
            ],
        ];
    }
}
