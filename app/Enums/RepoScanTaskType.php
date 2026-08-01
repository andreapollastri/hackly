<?php

namespace App\Enums;

enum RepoScanTaskType: string
{
    case SemgrepSast = 'semgrep_sast';
    case TrivySca = 'trivy_sca';
    case GitleaksSecrets = 'gitleaks_secrets';
    case CheckovIac = 'checkov_iac';
    case ComposerOsv = 'composer_osv';
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
            self::LaravelPhpAudit => 'Laravel PHP audit',
            self::LaravelLivePentest => 'Laravel live pentest',
        };
    }

    public function toolName(): string
    {
        return match ($this) {
            self::SemgrepSast => 'semgrep',
            self::TrivySca => 'trivy',
            self::GitleaksSecrets => 'gitleaks',
            self::CheckovIac => 'checkov',
            self::ComposerOsv => 'composer+osv',
            self::LaravelPhpAudit => 'hackly-laravel-audit',
            self::LaravelLivePentest => 'hackly-laravel-live',
        };
    }
}
