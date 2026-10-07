<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum FindingSeverity: string implements HasColor, HasIcon, HasLabel
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /**
     * Upper-case label used by PDF / Markdown reports.
     */
    public function label(): string
    {
        return strtoupper($this->value);
    }

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return $this->color();
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Low => Heroicon::OutlinedInformationCircle,
            self::Medium => Heroicon::OutlinedExclamationTriangle,
            self::High => Heroicon::OutlinedFire,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Low => 'info',
            self::Medium => 'warning',
            self::High => 'danger',
        };
    }

    public function hex(): string
    {
        return match ($this) {
            self::Low => '#0284c7',
            self::Medium => '#d97706',
            self::High => '#dc2626',
        };
    }

    /**
     * Higher = more severe. Use descending sort for most → least severe.
     */
    public function rank(): int
    {
        return match ($this) {
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }

    /**
     * SQL expression for ORDER BY (higher rank = more severe).
     */
    public static function orderByRankSql(string $column = 'severity'): string
    {
        return "CASE {$column} WHEN 'high' THEN 3 WHEN 'medium' THEN 2 WHEN 'low' THEN 1 ELSE 0 END";
    }

    public static function normalize(string $value): self
    {
        $value = strtolower(trim($value));

        return match (true) {
            str_contains($value, 'critical'),
            str_contains($value, 'high'),
            $value === '3',
            $value === '4' => self::High,
            str_contains($value, 'medium'),
            $value === '2' => self::Medium,
            default => self::Low,
        };
    }
}
