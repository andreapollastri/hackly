<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ScanTaskStatus: string implements HasColor, HasIcon, HasLabel
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending, self::Queued => 'gray',
            self::Running => 'info',
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Skipped => 'warning',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Pending, self::Queued => Heroicon::OutlinedClock,
            self::Running => Heroicon::OutlinedArrowPath,
            self::Completed => Heroicon::OutlinedCheckCircle,
            self::Failed => Heroicon::OutlinedXCircle,
            self::Skipped => Heroicon::OutlinedMinusCircle,
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, self::finished(), true);
    }

    /**
     * @return list<self>
     */
    public static function finished(): array
    {
        return [self::Completed, self::Failed, self::Skipped];
    }
}
