<?php

namespace App\Domain\Compliance;

enum ComplianceFramework: string
{
    case Dora = 'dora';
    case Soc2 = 'soc2';
    case Nis2 = 'nis2';
    case Iso27001 = 'iso-27001';
    case PciDss = 'pci-dss';
    case Gdpr = 'gdpr';
    case NistCsf = 'nist-csf';
    case OwaspTop10 = 'owasp-top-10';
    case CisV8 = 'cis-v8';
    case Hipaa = 'hipaa';
    case CyberEssentials = 'cyber-essentials';
    case EssentialEight = 'essential-eight';

    public function label(): string
    {
        return match ($this) {
            self::Dora => 'DORA',
            self::Soc2 => 'SOC 2',
            self::Nis2 => 'NIS2',
            self::Iso27001 => 'ISO/IEC 27001',
            self::PciDss => 'PCI DSS',
            self::Gdpr => 'GDPR',
            self::NistCsf => 'NIST CSF 2.0',
            self::OwaspTop10 => 'OWASP Top 10',
            self::CisV8 => 'CIS Controls v8',
            self::Hipaa => 'HIPAA',
            self::CyberEssentials => 'Cyber Essentials',
            self::EssentialEight => 'Essential Eight',
        };
    }

    public function citation(): string
    {
        return match ($this) {
            self::Dora => 'Regulation (EU) 2022/2554 — digital operational resilience',
            self::Soc2 => 'AICPA Trust Services Criteria — Security',
            self::Nis2 => 'Directive (EU) 2022/2555 — Article 21 measures',
            self::Iso27001 => 'ISO/IEC 27001:2022 Annex A (selected controls)',
            self::PciDss => 'PCI DSS v4.0 — requirements Hackly can evidence',
            self::Gdpr => 'Regulation (EU) 2016/679 — Article 32 and related duties',
            self::NistCsf => 'NIST Cybersecurity Framework 2.0',
            self::OwaspTop10 => 'OWASP Top 10:2021',
            self::CisV8 => 'CIS Critical Security Controls v8 — Implementation Group 1',
            self::Hipaa => '45 CFR Part 164 — Security Rule (technical safeguards)',
            self::CyberEssentials => 'NCSC Cyber Essentials — five technical controls',
            self::EssentialEight => 'Australian Essential Eight',
        };
    }

    public function summary(): string
    {
        return match ($this) {
            self::Dora => 'ICT risk, protection, detection, testing and third-party code risk for financial entities.',
            self::Soc2 => 'Security criteria: access boundaries, transmission, change, vendor risk and monitoring.',
            self::Nis2 => 'Risk analysis, supply chain, secure development, vulnerability handling and cryptography.',
            self::Iso27001 => 'Asset inventory, supplier risk, vulnerability management, cryptography and secure coding.',
            self::PciDss => 'Secure development, vulnerability management, hardening, encryption and external testing.',
            self::Gdpr => 'Security of processing: encryption in transit, integrity, testing and processor libraries.',
            self::NistCsf => 'Identify, Protect, Detect and Respond outcomes backed by scan evidence.',
            self::OwaspTop10 => 'How open findings line up with the ten most common web application risks.',
            self::CisV8 => 'Inventory, configuration, access, patching and application security basics.',
            self::Hipaa => 'Technical safeguards for access, transmission, integrity and periodic evaluation.',
            self::CyberEssentials => 'Firewalls, secure configuration and security update management.',
            self::EssentialEight => 'Application patching and user-application hardening. Several strategies need evidence Hackly does not collect.',
        };
    }
}
