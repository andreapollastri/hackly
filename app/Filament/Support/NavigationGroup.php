<?php

namespace App\Filament\Support;

use Filament\Support\Contracts\HasLabel;

enum NavigationGroup: string implements HasLabel
{
    case AttackSurface = 'attack_surface';
    case Security = 'security';
    case Settings = 'settings';

    public function getLabel(): string
    {
        return match ($this) {
            self::AttackSurface => 'Attack surface',
            self::Security => 'Security',
            self::Settings => 'Settings',
        };
    }
}
