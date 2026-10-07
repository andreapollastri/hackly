<?php

namespace App\Filament\Widgets;

use App\Enums\FindingSeverity;
use App\Models\Finding;
use Filament\Widgets\ChartWidget;

class FindingsTrendChart extends ChartWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'New issues';

    protected ?string $description = 'Issues first detected per day, by severity.';

    protected ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 3];

    public ?string $filter = '30';

    protected function getFilters(): ?array
    {
        return [
            '14' => 'Last 14 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $days = (int) ($this->filter ?: 30);
        $since = now()->subDays($days - 1)->startOfDay();

        $rows = Finding::query()
            ->issues()
            ->reorder()
            ->where('created_at', '>=', $since)
            ->get(['severity', 'created_at']);

        $labels = [];
        $keys = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $since->copy()->addDays($i);
            $keys[] = $date->toDateString();
            $labels[] = $date->format($days > 30 ? 'M j' : 'D j');
        }

        $datasets = [];

        foreach ([FindingSeverity::High, FindingSeverity::Medium, FindingSeverity::Low] as $severity) {
            $counts = $rows->where('severity', $severity)->countBy(fn (Finding $f) => $f->created_at->toDateString());

            $datasets[] = [
                'label' => $severity->getLabel(),
                'data' => array_map(fn (string $key): int => (int) ($counts[$key] ?? 0), $keys),
                'backgroundColor' => match ($severity) {
                    FindingSeverity::High => '#ef4444',
                    FindingSeverity::Medium => '#f59e0b',
                    FindingSeverity::Low => '#38bdf8',
                },
                'borderRadius' => 3,
                'maxBarThickness' => 18,
            ];
        }

        return [
            'labels' => $labels,
            'datasets' => $datasets,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['position' => 'bottom', 'labels' => ['usePointStyle' => true, 'boxWidth' => 8]],
            ],
            'scales' => [
                'x' => ['stacked' => true, 'grid' => ['display' => false], 'ticks' => ['maxRotation' => 0, 'autoSkipPadding' => 12]],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }
}
