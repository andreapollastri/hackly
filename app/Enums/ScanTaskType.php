<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ScanTaskType: string implements HasDescription, HasIcon, HasLabel
{
    case DnsInfo = 'dns_info';
    case MailSecurity = 'mail_security';
    case TlsCheck = 'tls_check';
    case PortScan = 'port_scan';
    case SubdomainEnum = 'subdomain_enum';
    case OriginExposure = 'origin_exposure';
    case TechFingerprint = 'tech_fingerprint';
    case PathDiscovery = 'path_discovery';
    case NucleiOwasp = 'nuclei_owasp';
    case ZapBaseline = 'zap_baseline';

    public function label(): string
    {
        return $this->getLabel();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::DnsInfo => 'DNS & WHOIS',
            self::MailSecurity => 'Mail security',
            self::TlsCheck => 'TLS certificate',
            self::PortScan => 'Port scan',
            self::SubdomainEnum => 'Subdomain discovery',
            self::OriginExposure => 'Origin exposure',
            self::TechFingerprint => 'Tech fingerprint',
            self::PathDiscovery => 'Path discovery',
            self::NucleiOwasp => 'Nuclei templates',
            self::ZapBaseline => 'OWASP ZAP baseline',
        };
    }

    public function toolName(): string
    {
        return match ($this) {
            self::DnsInfo => 'dig · whois',
            self::MailSecurity => 'SPF · DKIM · DMARC',
            self::TlsCheck => 'openssl',
            self::PortScan => 'nmap',
            self::SubdomainEnum => 'wordlist · CT logs',
            self::OriginExposure => 'host-header probes',
            self::TechFingerprint => 'http',
            self::PathDiscovery => 'soft wordlist',
            self::NucleiOwasp => 'nuclei',
            self::ZapBaseline => 'zap',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::DnsInfo => 'Records, DNSSEC, CAA, zone transfer and wildcard checks.',
            self::MailSecurity => 'SPF, DKIM, DMARC, MTA-STS and TLS-RPT posture.',
            self::TlsCheck => 'Certificate expiry, hostname match and legacy protocols.',
            self::PortScan => 'Top TCP ports with light service detection.',
            self::SubdomainEnum => 'Wordlist + Certificate Transparency, with takeover fingerprints.',
            self::OriginExposure => 'Checks whether the origin answers directly, bypassing the edge.',
            self::TechFingerprint => 'Headers, cookies, CORS and Laravel debug exposure.',
            self::PathDiscovery => 'Sensitive paths such as .env, Telescope and Horizon.',
            self::NucleiOwasp => 'OWASP, PHP, Laravel and dotenv vulnerability templates.',
            self::ZapBaseline => 'Passive DAST baseline with calibrated severities.',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::DnsInfo => Heroicon::OutlinedServerStack,
            self::MailSecurity => Heroicon::OutlinedEnvelope,
            self::TlsCheck => Heroicon::OutlinedLockClosed,
            self::PortScan => Heroicon::OutlinedSignal,
            self::SubdomainEnum => Heroicon::OutlinedGlobeAlt,
            self::OriginExposure => Heroicon::OutlinedServer,
            self::TechFingerprint => Heroicon::OutlinedFingerPrint,
            self::PathDiscovery => Heroicon::OutlinedFolderOpen,
            self::NucleiOwasp => Heroicon::OutlinedBugAnt,
            self::ZapBaseline => Heroicon::OutlinedBolt,
        };
    }
}
