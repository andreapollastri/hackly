<?php

namespace App\Enums;

enum Reachability: string
{
    case Reachable = 'reachable';
    case Unreachable = 'unreachable';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Reachable => 'Reachable',
            self::Unreachable => 'Unreachable',
            self::Unknown => 'Unknown',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Reachable => 'danger',
            self::Unreachable => 'success',
            self::Unknown => 'gray',
        };
    }
}
