<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum RepoScanTaskType: string implements HasDescription, HasIcon, HasLabel
{
    case SemgrepSast = 'semgrep_sast';
    case TrivySca = 'trivy_sca';
    case GitleaksSecrets = 'gitleaks_secrets';
    case CheckovIac = 'checkov_iac';
    case ComposerOsv = 'composer_osv';
    case ComposerOutdated = 'composer_outdated';
    case LaravelPhpAudit = 'laravel_php_audit';
    case LaravelLivePentest = 'laravel_live_pentest';

    public function label(): string
    {
        return match ($this) {
            self::SemgrepSast => 'Semgrep SAST (PHP/Laravel)',
            self::TrivySca => 'Trivy SCA',
            self::GitleaksSecrets => 'Gitleaks secrets',
            self::CheckovIac => 'Checkov IaC',
            self::ComposerOsv => 'Composer / OSV',
            self::ComposerOutdated => 'Composer outdated',
            self::LaravelPhpAudit => 'Laravel PHP audit',
            self::LaravelLivePentest => 'Laravel live pentest',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function toolName(): string
    {
        return match ($this) {
            self::SemgrepSast => 'semgrep',
            self::TrivySca => 'trivy',
            self::GitleaksSecrets => 'gitleaks',
            self::CheckovIac => 'checkov',
            self::ComposerOsv => 'composer+osv',
            self::ComposerOutdated => 'composer outdated',
            self::LaravelPhpAudit => 'hackly-laravel-audit',
            self::LaravelLivePentest => 'hackly-laravel-live',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::SemgrepSast => 'Static analysis with the p/php ruleset.',
            self::TrivySca => 'Vulnerable dependencies from lockfiles.',
            self::GitleaksSecrets => 'Hard-coded credentials and API keys.',
            self::CheckovIac => 'Docker, Terraform and GitHub Actions misconfigurations.',
            self::ComposerOsv => 'composer audit + OSV advisories.',
            self::ComposerOutdated => 'Abandoned packages and direct dependencies a major version behind.',
            self::LaravelPhpAudit => 'Committed .env, debug packages, mass assignment and more.',
            self::LaravelLivePentest => 'Live probes against linked, verified targets.',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::SemgrepSast => Heroicon::OutlinedCodeBracket,
            self::TrivySca => Heroicon::OutlinedCube,
            self::GitleaksSecrets => Heroicon::OutlinedKey,
            self::CheckovIac => Heroicon::OutlinedServerStack,
            self::ComposerOsv => Heroicon::OutlinedArchiveBox,
            self::ComposerOutdated => Heroicon::OutlinedClock,
            self::LaravelPhpAudit => Heroicon::OutlinedDocumentMagnifyingGlass,
            self::LaravelLivePentest => Heroicon::OutlinedBolt,
        };
    }
}
