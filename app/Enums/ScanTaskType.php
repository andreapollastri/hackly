<?php

namespace App\Enums;

enum ScanTaskType: string
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
}
