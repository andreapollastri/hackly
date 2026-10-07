<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum FindingStatus: string implements HasColor, HasIcon, HasLabel
{
    case Open = 'open';
    case Ack = 'ack';
    case Fixed = 'fixed';
    case FalsePositive = 'false_positive';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Ack => 'Acknowledged',
            self::Fixed => 'Fixed',
            self::FalsePositive => 'False positive',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Ack => 'warning',
            self::Fixed => 'success',
            self::FalsePositive => 'gray',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Open => Heroicon::OutlinedExclamationCircle,
            self::Ack => Heroicon::OutlinedEye,
            self::Fixed => Heroicon::OutlinedCheckCircle,
            self::FalsePositive => Heroicon::OutlinedNoSymbol,
        };
    }
}
