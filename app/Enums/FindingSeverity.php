<?php

namespace App\Enums;

enum FindingSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return strtoupper($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Low => 'success',
            self::Medium => 'warning',
            self::High => 'danger',
        };
    }

    public function hex(): string
    {
        return match ($this) {
            self::Low => '#16a34a',
            self::Medium => '#ea580c',
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
