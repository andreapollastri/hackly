<?php

namespace App\Domain\Compliance;

/**
 * Control catalogue. Each control is scored only from evidence Hackly actually collects.
 * Requirements a scanner cannot see are marked out of scope instead of being treated as met.
 */
class ComplianceCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function controls(ComplianceFramework $framework): array
    {
        return match ($framework) {
            ComplianceFramework::Dora => self::dora(),
            ComplianceFramework::Soc2 => self::soc2(),
            ComplianceFramework::Nis2 => self::nis2(),
            ComplianceFramework::Iso27001 => self::iso27001(),
            ComplianceFramework::PciDss => self::pci(),
            ComplianceFramework::Gdpr => self::gdpr(),
            ComplianceFramework::NistCsf => self::nist(),
            ComplianceFramework::OwaspTop10 => self::owasp(),
            ComplianceFramework::CisV8 => self::cis(),
            ComplianceFramework::Hipaa => self::hipaa(),
            ComplianceFramework::CyberEssentials => self::cyberEssentials(),
            ComplianceFramework::EssentialEight => self::essentialEight(),
        };
    }

    /**
     * @param  list<string>  $categories
     * @param  list<string>  $tasks
     * @return array<string, mixed>
     */
    private static function findings(string $id, string $reference, string $title, string $requirement, array $categories, array $tasks = []): array
    {
        return [
            'id' => $id,
            'reference' => $reference,
            'title' => $title,
            'requirement' => $requirement,
            'kind' => 'findings',
            'categories' => $categories,
            'tasks' => $tasks,
            'note' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function kind(string $id, string $reference, string $title, string $requirement, string $kind, ?string $note = null): array
    {
        return [
            'id' => $id,
            'reference' => $reference,
            'title' => $title,
            'requirement' => $requirement,
            'kind' => $kind,
            'categories' => [],
            'tasks' => [],
            'note' => $note,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function dora(): array
    {
        return [
            self::kind('dora-art-8', 'Art. 8', 'Identification of ICT assets', 'Keep an inventory of the systems in scope and be able to show you control them.', 'inventory'),
            self::findings('dora-art-9-dev', 'Art. 9', 'Protection — secure development', 'Prevent vulnerable code, leaked secrets and unsafe Laravel patterns from reaching production.', ['sast', 'secret', 'laravel', 'iac'], ['semgrep_sast', 'gitleaks_secrets', 'laravel_php_audit', 'checkpoint', 'checkov_iac']),
            self::findings('dora-art-9-transit', 'Art. 9', 'Protection — data in transit', 'Protect network services with current TLS and sound mail authentication.', ['tls', 'certificate', 'protocol', 'spf', 'dkim', 'dmarc', 'mta_sts'], ['tls_check', 'mail_security']),
            self::findings('dora-art-10', 'Art. 10', 'Detection', 'Detect exposed origins, takeovers, zone leaks and anomalous DNS.', ['origin_exposure', 'subdomain_takeover', 'axfr', 'wildcard', 'dns'], ['dns_info', 'subdomain_enum', 'origin_exposure']),
            self::findings('dora-art-24', 'Art. 24', 'Digital operational resilience testing', 'Run vulnerability testing against verified targets and the code that deploys to them.', ['path_discovery', 'baseline', 'laravel-live', 'ports', 'open_port'], ['port_scan', 'path_discovery', 'nuclei_owasp', 'zap_baseline', 'laravel_live_pentest']),
            self::kind('dora-art-25', 'Art. 25', 'Advanced testing cadence', 'Threat-led testing is evidenced here by completed scans in the last 90 days. This is not a TLPT engagement.', 'testing'),
            self::findings('dora-art-28', 'Art. 28', 'ICT third-party risk', 'Track known vulnerabilities and abandoned or unsupported dependencies.', ['sca', 'dependency_health'], ['composer_osv', 'trivy_sca', 'composer_outdated', 'checkpoint']),
            self::kind('dora-art-11', 'Art. 11', 'Response', 'Open high and medium issues are triaged instead of left untouched.', 'triage'),
            self::kind('dora-art-11-bcp', 'Art. 11–12', 'Business continuity', 'Backup, recovery time and crisis communication are outside what these scanners observe.', 'out_of_scope', 'Hackly does not collect backup or continuity evidence. Record that in your BCP, not in this pack.'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function soc2(): array
    {
        return [
            self::findings('soc2-cc6.1', 'CC6.1', 'Logical access', 'Restrict exposure of debug endpoints, secrets and overly broad cookies.', ['secret', 'laravel_debug', 'path_discovery', 'cookie', 'laravel'], ['gitleaks_secrets', 'path_discovery', 'tech_fingerprint', 'laravel_php_audit', 'checkpoint']),
            self::findings('soc2-cc6.6', 'CC6.6', 'System boundaries', 'Limit exposed ports, origin bypass and missing browser boundaries.', ['ports', 'open_port', 'origin_exposure', 'headers', 'cors'], ['port_scan', 'origin_exposure', 'tech_fingerprint']),
            self::findings('soc2-cc6.7', 'CC6.7', 'Transmission confidentiality', 'Use sound TLS and authenticated mail paths.', ['tls', 'certificate', 'protocol', 'spf', 'dkim', 'dmarc'], ['tls_check', 'mail_security']),
            self::findings('soc2-cc7.1', 'CC7.1', 'Detection of changes and vulnerabilities', 'Vulnerability templates and baseline DAST have been run.', ['baseline', 'path_discovery', 'laravel-live'], ['nuclei_owasp', 'zap_baseline', 'path_discovery', 'laravel_live_pentest']),
            self::findings('soc2-cc8.1', 'CC8.1', 'Change management', 'Static analysis and infrastructure-as-code checks on the repositories you deploy.', ['sast', 'iac', 'laravel'], ['semgrep_sast', 'checkov_iac', 'laravel_php_audit', 'checkpoint']),
            self::findings('soc2-cc9.2', 'CC9.2', 'Vendor and third-party risk', 'Known-vulnerable and unhealthy dependencies are visible.', ['sca', 'dependency_health'], ['composer_osv', 'trivy_sca', 'composer_outdated', 'checkpoint']),
            self::kind('soc2-cc7.2', 'CC7.2', 'Monitoring', 'A completed scan in the last 90 days stands in for continuous detection. It is not a SOC.', 'testing'),
            self::kind('soc2-cc7.3', 'CC7.3', 'Evaluation of security events', 'High and medium findings are acknowledged or fixed.', 'triage'),
            self::kind('soc2-cc6.4', 'CC6.4', 'Physical access', 'Physical and data-centre controls are outside this scanner.', 'out_of_scope', 'Collect physical-access evidence from your hosting provider.'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function nis2(): array
    {
        return [
            self::kind('nis2-21a', 'Art. 21(2)(a)', 'Risk analysis and information-system security', 'Know the assets in scope before claiming a risk analysis.', 'inventory'),
            self::findings('nis2-21d', 'Art. 21(2)(d)', 'Supply-chain security', 'Dependencies and their known vulnerabilities are reviewed.', ['sca', 'dependency_health'], ['composer_osv', 'trivy_sca', 'composer_outdated', 'checkpoint']),
            self::findings('nis2-21e', 'Art. 21(2)(e)', 'Secure development and maintenance', 'SAST, secrets and IaC findings are handled in the repositories you ship.', ['sast', 'secret', 'iac', 'laravel'], ['semgrep_sast', 'gitleaks_secrets', 'checkov_iac', 'laravel_php_audit', 'checkpoint']),
            self::findings('nis2-21f', 'Art. 21(2)(f)', 'Vulnerability handling and disclosure', 'External vulnerability testing has results, and those results are triaged.', ['baseline', 'path_discovery', 'ports', 'open_port', 'laravel-live'], ['nuclei_owasp', 'zap_baseline', 'port_scan', 'path_discovery']),
            self::findings('nis2-21h', 'Art. 21(2)(h)', 'Cryptography', 'TLS configuration and certificates meet a current baseline.', ['tls', 'certificate', 'protocol'], ['tls_check']),
            self::findings('nis2-21i', 'Art. 21(2)(i)', 'Human-facing hygiene on the edge', 'Security headers, cookies and mail authentication reduce trivial exposure.', ['headers', 'cookie', 'cors', 'spf', 'dkim', 'dmarc'], ['tech_fingerprint', 'mail_security']),
            self::kind('nis2-21b', 'Art. 21(2)(b)', 'Incident handling', 'Open high and medium issues have an owner or a fix.', 'triage'),
            self::kind('nis2-21c', 'Art. 21(2)(c)', 'Business continuity and crisis', 'Backup, disaster recovery and crisis management are not observed by these scans.', 'out_of_scope', 'Map this article to your continuity plan. Hackly will not mark it met.'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function iso27001(): array
    {
        return [
            self::kind('iso-a5.9', 'A.5.9', 'Inventory of information and other associated assets', 'Verified targets and registered repositories are the inventory Hackly can show.', 'inventory'),
            self::findings('iso-a5.19', 'A.5.19–A.5.23', 'Supplier relationships', 'Third-party packages are identified and checked for known vulnerabilities.', ['sca', 'dependency_health'], ['composer_osv', 'trivy_sca', 'composer_outdated', 'checkpoint']),
            self::findings('iso-a8.8', 'A.8.8', 'Management of technical vulnerabilities', 'Vulnerability scans produce findings and those findings are worked.', ['baseline', 'path_discovery', 'sca', 'ports', 'open_port'], ['nuclei_owasp', 'zap_baseline', 'port_scan', 'composer_osv', 'trivy_sca']),
            self::findings('iso-a8.9', 'A.8.9', 'Configuration management', 'Exposed configuration, DNS weaknesses and IaC misconfigurations are visible.', ['headers', 'iac', 'dns', 'axfr', 'dnssec', 'caa', 'laravel_debug'], ['tech_fingerprint', 'checkov_iac', 'dns_info']),
            self::findings('iso-a8.24', 'A.8.24', 'Use of cryptography', 'Transport encryption is configured and not obviously legacy.', ['tls', 'certificate', 'protocol'], ['tls_check']),
            self::findings('iso-a8.25', 'A.8.25–A.8.28', 'Secure development and coding', 'SAST, secret scanning and Laravel-specific checks run on the repository.', ['sast', 'secret', 'laravel'], ['semgrep_sast', 'gitleaks_secrets', 'laravel_php_audit', 'checkpoint']),
            self::kind('iso-a5.24', 'A.5.24–A.5.26', 'Incident management', 'Security events represented by findings are triaged.', 'triage'),
            self::kind('iso-a5.29', 'A.5.29–A.5.30', 'ICT readiness for business continuity', 'Continuity testing is outside the scanner.', 'out_of_scope', 'Evidence belongs in your continuity test records.'),
            self::kind('iso-a7', 'A.7', 'Physical security', 'Physical controls are outside the scanner.', 'out_of_scope', 'Hackly does not visit facilities.'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function pci(): array
    {
        return [
            self::findings('pci-6.2', 'Req. 6.2', 'Bespoke and custom software is developed securely', 'Custom code is scanned for injection, unsafe patterns and secrets.', ['sast', 'secret', 'laravel'], ['semgrep_sast', 'gitleaks_secrets', 'laravel_php_audit', 'checkpoint']),
            self::findings('pci-6.3', 'Req. 6.3', 'Security vulnerabilities are identified and addressed', 'Known component vulnerabilities and external findings are tracked.', ['sca', 'dependency_health', 'baseline', 'path_discovery'], ['composer_osv', 'trivy_sca', 'nuclei_owasp', 'zap_baseline']),
            self::findings('pci-2.2', 'Req. 2.2', 'System components are configured securely', 'Unnecessary exposure and insecure defaults are looked for.', ['ports', 'open_port', 'headers', 'cookie', 'laravel_debug', 'iac'], ['port_scan', 'tech_fingerprint', 'checkov_iac']),
            self::findings('pci-4', 'Req. 4', 'Strong cryptography protects transmission', 'Cardholder-style data in transit would rely on the TLS posture Hackly measures.', ['tls', 'certificate', 'protocol'], ['tls_check']),
            self::kind('pci-11.3', 'Req. 11.3', 'External and internal vulnerability scans', 'A completed scan in the last 90 days is the evidence Hackly can attach. It is not an ASV scan.', 'testing'),
            self::kind('pci-6.3-triage', 'Req. 6.3.1', 'Vulnerabilities are ranked and fixed', 'High and medium findings are not left open without a decision.', 'triage'),
            self::kind('pci-12.10', 'Req. 12.10', 'Incident response plan', 'The existence of a written IR plan is not something a scanner can prove.', 'out_of_scope', 'Attach your incident-response plan separately. This pack only shows technical findings.'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function gdpr(): array
    {
        return [
            self::findings('gdpr-32-a', 'Art. 32(1)(a)', 'Encryption of personal data in transit', 'TLS and certificate posture for the domains you scan.', ['tls', 'certificate', 'protocol'], ['tls_check']),
            self::findings('gdpr-32-b', 'Art. 32(1)(b)', 'Confidentiality and integrity', 'Code, secrets, headers and access-exposure findings that could leak or alter data.', ['sast', 'secret', 'headers', 'cors', 'path_discovery', 'laravel_debug'], ['semgrep_sast', 'gitleaks_secrets', 'tech_fingerprint', 'path_discovery']),
            self::findings('gdpr-28', 'Art. 28', 'Processors embodied as libraries', 'Third-party packages are known and checked. This is not a register of data processors.', ['sca', 'dependency_health'], ['composer_osv', 'trivy_sca', 'composer_outdated', 'checkpoint']),
            self::findings('gdpr-25', 'Art. 25', 'Data protection by design and by default', 'Debug exposure, cookies and Laravel misconfigurations that leak more than they should.', ['laravel', 'laravel_debug', 'cookie', 'headers', 'laravel-live'], ['laravel_php_audit', 'checkpoint', 'tech_fingerprint', 'laravel_live_pentest']),
            self::kind('gdpr-32-d', 'Art. 32(1)(d)', 'Regular testing and evaluation', 'Completed scans in the last 90 days.', 'testing'),
            self::kind('gdpr-32-c', 'Art. 32(1)(c)', 'Availability and resilience', 'Availability, backups and restoration tests are outside the scanner.', 'out_of_scope', 'Art. 32(1)(c) needs operational evidence (backups, failover), which Hackly does not collect.'),
            self::kind('gdpr-33', 'Art. 33–34', 'Breach notification', 'Notification deadlines and templates are a process, not a scan result.', 'out_of_scope', 'Keep the breach procedure with your privacy counsel. Do not treat a green control here as legal advice.'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function nist(): array
    {
        return [
            self::kind('nist-id-am', 'ID.AM', 'Asset management', 'Inventoried, ownership-verified targets and repositories.', 'inventory'),
            self::findings('nist-pr-ds', 'PR.DS', 'Data security', 'Secrets and transport protection.', ['secret', 'tls', 'certificate', 'protocol'], ['gitleaks_secrets', 'tls_check']),
            self::findings('nist-pr-ps', 'PR.PS', 'Platform security', 'Hardening of the edge: ports, headers, DNS and configuration.', ['ports', 'open_port', 'headers', 'cookie', 'cors', 'dns', 'iac'], ['port_scan', 'tech_fingerprint', 'dns_info', 'checkov_iac']),
            self::findings('nist-id-ra', 'ID.RA', 'Risk assessment — vulnerabilities', 'Application and dependency vulnerabilities are identified.', ['sast', 'sca', 'baseline', 'path_discovery', 'dependency_health'], ['semgrep_sast', 'composer_osv', 'trivy_sca', 'nuclei_owasp', 'zap_baseline']),
            self::kind('nist-de-cm', 'DE.CM', 'Continuous monitoring', 'A scan completed in the last 90 days.', 'testing'),
            self::kind('nist-rs-ma', 'RS.MA', 'Incident management', 'Findings above low severity are triaged.', 'triage'),
            self::kind('nist-rc-rp', 'RC.RP', 'Recovery planning', 'Recovery plans are outside the scanner.', 'out_of_scope', 'NIST Recover outcomes need restore tests, not DAST output.'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function owasp(): array
    {
        return [
            self::findings('owasp-a01', 'A01', 'Broken access control', 'Exposed paths, origin bypass, debug mode and permissive CORS.', ['path_discovery', 'origin_exposure', 'laravel_debug', 'cors', 'laravel-live'], ['path_discovery', 'origin_exposure', 'tech_fingerprint', 'laravel_live_pentest']),
            self::findings('owasp-a02', 'A02', 'Cryptographic failures', 'TLS protocol, certificate and cipher posture.', ['tls', 'certificate', 'protocol'], ['tls_check']),
            self::findings('owasp-a03', 'A03', 'Injection', 'SAST and baseline DAST signals for injection-class bugs. Coverage is heuristic.', ['sast', 'baseline', 'laravel'], ['semgrep_sast', 'zap_baseline', 'laravel_php_audit', 'checkpoint']),
            self::findings('owasp-a05', 'A05', 'Security misconfiguration', 'Headers, cookies, DNS, IaC and default exposures.', ['headers', 'cookie', 'http_methods', 'iac', 'dns', 'axfr', 'laravel_debug'], ['tech_fingerprint', 'checkov_iac', 'dns_info']),
            self::findings('owasp-a06', 'A06', 'Vulnerable and outdated components', 'SCA and dependency health.', ['sca', 'dependency_health'], ['composer_osv', 'trivy_sca', 'composer_outdated', 'checkpoint']),
            self::findings('owasp-a07', 'A07', 'Identification and authentication failures', 'Cookie flags and live Laravel auth-adjacent probes. This is not a full auth review.', ['cookie', 'laravel-live', 'laravel'], ['tech_fingerprint', 'laravel_live_pentest', 'laravel_php_audit', 'checkpoint']),
            self::findings('owasp-a08', 'A08', 'Software and data integrity failures', 'Secrets in git and unsigned or weakly authenticated mail.', ['secret', 'dmarc', 'spf', 'dkim'], ['gitleaks_secrets', 'mail_security']),
            self::findings('owasp-a10', 'A10', 'Server-side request forgery', 'Only the SAST and live probes that happen to match. Not a dedicated SSRF assessment.', ['sast', 'laravel-live', 'baseline'], ['semgrep_sast', 'laravel_live_pentest', 'zap_baseline']),
            self::kind('owasp-a04', 'A04', 'Insecure design', 'Design flaws are not something a scanner can close.', 'out_of_scope', 'Threat modelling still has to be done by people.'),
            self::kind('owasp-a09', 'A09', 'Security logging and monitoring failures', 'Hackly does not read application logs or SIEM coverage.', 'out_of_scope', 'Show log retention and alerting from your logging stack.'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function cis(): array
    {
        return [
            self::kind('cis-1', 'CIS 1', 'Inventory and control of enterprise assets', 'Verified domains and registered repositories.', 'inventory'),
            self::findings('cis-2', 'CIS 2', 'Inventory and control of software assets', 'Dependency inventory via SCA.', ['sca', 'dependency_health'], ['composer_osv', 'trivy_sca', 'composer_outdated', 'checkpoint']),
            self::findings('cis-3', 'CIS 3', 'Data protection', 'Secrets and transit encryption.', ['secret', 'tls', 'certificate', 'protocol'], ['gitleaks_secrets', 'tls_check']),
            self::findings('cis-4', 'CIS 4', 'Secure configuration of enterprise assets and software', 'Headers, DNS, IaC and debug exposure.', ['headers', 'cookie', 'iac', 'dns', 'laravel_debug'], ['tech_fingerprint', 'dns_info', 'checkov_iac']),
            self::findings('cis-6', 'CIS 6', 'Access control management', 'Exposed admin paths and origin bypass. Account directories are not in scope.', ['path_discovery', 'origin_exposure', 'laravel_debug'], ['path_discovery', 'origin_exposure', 'tech_fingerprint']),
            self::findings('cis-7', 'CIS 7', 'Continuous vulnerability management', 'Vulnerability scans plus triage of what they find.', ['baseline', 'sca', 'path_discovery', 'ports', 'open_port'], ['nuclei_owasp', 'zap_baseline', 'port_scan', 'composer_osv']),
            self::findings('cis-16', 'CIS 16', 'Application software security', 'SAST, secrets and framework checks.', ['sast', 'secret', 'laravel'], ['semgrep_sast', 'gitleaks_secrets', 'laravel_php_audit', 'checkpoint']),
            self::kind('cis-7-triage', 'CIS 7.4', 'Remediate detected vulnerabilities', 'High and medium issues are acknowledged or fixed.', 'triage'),
            self::kind('cis-5', 'CIS 5', 'Account management', 'Identity provider and joiner-mover-leaver process are outside the scanner.', 'out_of_scope', 'Export this from your IdP, not from Hackly.'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function hipaa(): array
    {
        return [
            self::kind('hipaa-164.308-a1', '164.308(a)(1)', 'Security management — risk analysis', 'An asset inventory is the starting point of a risk analysis. It is not the analysis itself.', 'inventory'),
            self::findings('hipaa-164.312-a1', '164.312(a)(1)', 'Access control', 'Exposed paths, secrets and debug interfaces that would bypass access control.', ['secret', 'path_discovery', 'laravel_debug', 'origin_exposure'], ['gitleaks_secrets', 'path_discovery', 'tech_fingerprint', 'origin_exposure']),
            self::findings('hipaa-164.312-e', '164.312(e)(1)', 'Transmission security', 'TLS protecting data in motion.', ['tls', 'certificate', 'protocol'], ['tls_check']),
            self::findings('hipaa-164.312-c', '164.312(c)(1)', 'Integrity', 'Code and dependency issues that can alter or expose ePHI-bearing applications.', ['sast', 'sca', 'laravel'], ['semgrep_sast', 'composer_osv', 'trivy_sca', 'laravel_php_audit', 'checkpoint']),
            self::kind('hipaa-164.308-a8', '164.308(a)(8)', 'Evaluation', 'Periodic technical evaluation via scans completed in the last 90 days.', 'testing'),
            self::kind('hipaa-triage', '164.308(a)(1)(ii)(A)', 'Risk management', 'Identified high and medium risks are being worked.', 'triage'),
            self::kind('hipaa-164.312-b', '164.312(b)', 'Audit controls', 'Application and infrastructure audit logs are not collected by Hackly.', 'out_of_scope', 'HIPAA audit controls need log evidence from the system that stores ePHI.'),
            self::kind('hipaa-physical', '164.310', 'Physical safeguards', 'Facility access is outside the scanner.', 'out_of_scope', 'Physical safeguard documentation stays with the covered entity.'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function cyberEssentials(): array
    {
        return [
            self::findings('ce-firewalls', 'Firewalls', 'Boundary protection', 'Unexpected open ports and origin exposure.', ['ports', 'open_port', 'origin_exposure'], ['port_scan', 'origin_exposure']),
            self::findings('ce-config', 'Secure configuration', 'Secure configuration', 'Default exposures, headers, cookies and debug mode.', ['headers', 'cookie', 'laravel_debug', 'http_methods', 'dns'], ['tech_fingerprint', 'dns_info']),
            self::findings('ce-updates', 'Security update management', 'Security update management', 'Known-vulnerable and stale dependencies.', ['sca', 'dependency_health'], ['composer_osv', 'trivy_sca', 'composer_outdated', 'checkpoint']),
            self::kind('ce-access', 'User access control', 'User access control', 'Account provisioning and admin separation are not visible to these scanners.', 'out_of_scope', 'Cyber Essentials assessors will ask for user-access evidence directly.'),
            self::kind('ce-malware', 'Malware protection', 'Malware protection', 'Endpoint anti-malware is not measured here.', 'out_of_scope', 'Show endpoint protection from your device fleet, not from a web scan.'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function essentialEight(): array
    {
        return [
            self::findings('e8-patch-apps', 'Patch applications', 'Application patching', 'Vulnerable and outdated application dependencies.', ['sca', 'dependency_health'], ['composer_osv', 'trivy_sca', 'composer_outdated', 'checkpoint']),
            self::findings('e8-harden', 'User application hardening', 'Browser and app hardening', 'Security headers and cookie flags on the scanned origins.', ['headers', 'cookie', 'cors', 'http_methods'], ['tech_fingerprint']),
            self::findings('e8-app-control-hint', 'Application control (partial)', 'What the repository allows to ship', 'IaC and secret scanning are a thin slice of application control, not a full allow-list.', ['iac', 'secret'], ['checkov_iac', 'gitleaks_secrets']),
            self::kind('e8-patch-os', 'Patch operating systems', 'Operating system patching', 'Host OS patch level is not collected.', 'out_of_scope', 'Use your fleet or cloud patch report for this strategy.'),
            self::kind('e8-mfa', 'Multi-factor authentication', 'Multi-factor authentication', 'Hackly does not log into your IdP to prove MFA coverage.', 'out_of_scope', 'Export MFA enrolment from the identity provider.'),
            self::kind('e8-admin', 'Restrict administrative privileges', 'Administrative privileges', 'Privileged-access reviews are outside the scanner.', 'out_of_scope', 'Evidence is an admin-access review, not a DAST result.'),
            self::kind('e8-macros', 'Restrict Microsoft Office macros', 'Office macros', 'Workstation macro policy is not visible from a domain or GitHub scan.', 'out_of_scope', 'Collect this from endpoint management.'),
            self::kind('e8-backups', 'Regular backups', 'Backups', 'Backup configuration and restore tests are outside the scanner.', 'out_of_scope', 'Attach backup job history and a restore test.'),
        ];
    }
}
