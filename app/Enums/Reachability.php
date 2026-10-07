<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum Reachability: string implements HasColor, HasIcon, HasLabel
{
    case Reachable = 'reachable';
    case Unreachable = 'unreachable';
    case Unknown = 'unknown';

    public function label(): string
    {
        return $this->getLabel();
    }

    public function color(): string
    {
        return $this->getColor();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Reachable => 'Reachable',
            self::Unreachable => 'Unreachable',
            self::Unknown => 'Unknown',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Reachable => 'danger',
            self::Unreachable => 'success',
            self::Unknown => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Reachable => Heroicon::OutlinedBolt,
            self::Unreachable => Heroicon::OutlinedShieldCheck,
            self::Unknown => Heroicon::OutlinedQuestionMarkCircle,
        };
    }
}
