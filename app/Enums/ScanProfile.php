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
    case Pentest = 'pentest';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pentest => 'Penetration test',
            default => ucfirst($this->value),
        };
    }

    /**
     * Profiles offered by the normal "Start scan" action.
     * Penetration tests start from their own engagement. A DNS-verified target needs no second check.
     *
     * @return array<string, string>
     */
    public static function selectable(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if ($case === self::Pentest) {
                continue;
            }

            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Quick => 'gray',
            self::Standard => 'primary',
            self::Deep => 'info',
            self::Pentest => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Quick => Heroicon::OutlinedBolt,
            self::Standard => Heroicon::OutlinedShieldCheck,
            self::Deep => Heroicon::OutlinedBeaker,
            self::Pentest => Heroicon::OutlinedBugAnt,
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
            self::Pentest => 'Uses the target DNS verification already on file, then scoped ports, the declared API and login, technology exposures, Nuclei and a longer ZAP run.',
        };
    }

    /**
     * Description for repository (SAST / SCA) scans.
     */
    public function repositoryDescription(): string
    {
        return match ($this) {
            self::Quick => 'Secrets, Composer/OSV advisories, the Laravel static audit, and all 30 Checkpoint checks.',
            self::Standard => 'Quick + Semgrep SAST, Trivy and Composer outdated (dependency health).',
            self::Deep => 'Standard + Checkov for Docker, Terraform and CI workflows.',
            self::Pentest => 'Penetration tests run against an authorized target, not a repository.',
        };
    }
}
