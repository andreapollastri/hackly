<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ScanProfile: string implements HasColor, HasDescription, HasIcon, HasLabel
{
    case Quick = 'quick';
    case Standard = 'standard';
    case Deep = 'deep';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Quick => 'gray',
            self::Standard => 'primary',
            self::Deep => 'info',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Quick => Heroicon::OutlinedBolt,
            self::Standard => Heroicon::OutlinedShieldCheck,
            self::Deep => Heroicon::OutlinedBeaker,
        };
    }

    /**
     * Description for target (DAST / attack-surface) scans.
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::Quick => 'DNS, mail security, TLS, top ports and tech fingerprint. A few minutes.',
            self::Standard => 'Quick + subdomains, origin exposure, path discovery and Nuclei templates.',
            self::Deep => 'Standard + OWASP ZAP baseline. Slowest — subject to a cooldown between runs.',
        };
    }

    /**
     * Description for repository (SAST / SCA) scans.
     */
    public function repositoryDescription(): string
    {
        return match ($this) {
            self::Quick => 'Secrets, Composer/OSV advisories and the Laravel static audit.',
            self::Standard => 'Quick + Semgrep SAST, Trivy and Composer outdated (dependency health).',
            self::Deep => 'Standard + Checkov for Docker, Terraform and CI workflows.',
        };
    }
}
