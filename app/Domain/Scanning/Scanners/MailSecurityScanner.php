<?php

namespace App\Domain\Scanning\Scanners;

use App\Domain\Scanning\DTO\BinaryResult;
use App\Domain\Scanning\DTO\ScannerFinding;
use App\Domain\Scanning\Services\BinaryRunner;
use App\Enums\FindingSeverity;
use App\Enums\ScanTaskType;
use App\Models\Asset;
use App\Models\ScanTask;
use Illuminate\Support\Facades\Http;

class MailSecurityScanner extends AbstractScanner
{
    /** @var list<string> */
    private array $commonDkimSelectors;

    public function type(): ScanTaskType
    {
        return ScanTaskType::MailSecurity;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('hackly.mail_security.timeout', 120);
    }

    public function estimateCost(): int
    {
        return 1;
    }

    public function buildCommand(Asset $asset, ScanTask $task, string $outputPath): array
    {
        $this->ensureOutputDir($outputPath);

        return [
            $this->binary('dig'),
            '+short',
            'MX',
            $asset->value,
        ];
    }

    public function parse(Asset $asset, ScanTask $task, BinaryResult $result): array
    {
        $domain = strtolower(trim($asset->value));
        $this->commonDkimSelectors = config('hackly.mail_security.dkim_selectors', []);

        $mx = $this->parseMxRecords($result->stdout);
        $spf = $this->lookupSpfRecords($domain);
        $dmarc = $this->lookupTxtRecords('_dmarc.'.$domain);
        $dkim = $this->discoverDkim($domain);
        $mtaSts = $this->lookupTxtRecords('_mta-sts.'.$domain);
        $tlsRpt = $this->lookupTxtRecords('_smtp._tls.'.$domain);
        $bimi = $this->lookupTxtRecords('default._bimi.'.$domain);

        $findings = [];
        $findings = [...$findings, ...$this->analyzeMx($asset, $domain, $mx)];
        $findings = [...$findings, ...$this->analyzeSpf($asset, $domain, $spf, $mx)];
        $findings = [...$findings, ...$this->analyzeDkim($asset, $domain, $dkim, $mx)];
        $findings = [...$findings, ...$this->analyzeDmarc($asset, $domain, $dmarc, $mx)];
        $findings = [...$findings, ...$this->analyzeMtaSts($asset, $domain, $mtaSts, $mx)];
        $findings = [...$findings, ...$this->analyzeTlsRpt($asset, $domain, $tlsRpt, $mx)];
        $findings = [...$findings, ...$this->analyzeBimi($asset, $domain, $bimi, $dmarc)];

        if ($findings === []) {
            $findings[] = new ScannerFinding(
                title: 'Mail DNS posture looks healthy',
                severity: FindingSeverity::Low,
                source: 'mail',
                category: 'mail',
                description: 'MX/SPF/DKIM/DMARC checks did not raise issues for this domain.',
                evidence: [
                    'mx' => $mx,
                    'spf' => $spf,
                    'dkim_selectors' => array_keys($dkim),
                    'dmarc' => $dmarc,
                ],
                fingerprint: 'mail-ok-'.$asset->id,
            );
        }

        return $findings;
    }

    /**
     * @return list<array{priority: int, host: string}>
     */
    private function parseMxRecords(string $stdout): array
    {
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', $stdout) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(\d+)\s+(\S+)\.?$/', $line, $m)) {
                $rows[] = [
                    'priority' => (int) $m[1],
                    'host' => strtolower(rtrim($m[2], '.')),
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $rows;
    }

    /**
     * @param  list<array{priority: int, host: string}>  $mx
     * @return list<ScannerFinding>
     */
    private function analyzeMx(Asset $asset, string $domain, array $mx): array
    {
        $findings = [];

        if ($mx === []) {
            $findings[] = new ScannerFinding(
                title: 'No MX records found',
                severity: FindingSeverity::Medium,
                source: 'mail',
                category: 'mx',
                description: "{$domain} has no MX records. Inbound mail will fail unless null MX is intentional; outbound spoofing risk remains without SPF/DMARC.",
                evidence: ['domain' => $domain, 'mx' => []],
                fingerprint: 'mail-mx-missing-'.$asset->id,
            );

            return $findings;
        }

        $nullMx = collect($mx)->contains(
            fn (array $row): bool => $row['priority'] === 0 && in_array($row['host'], ['.', ''], true)
        );

        if ($nullMx) {
            $findings[] = new ScannerFinding(
                title: 'Null MX published (no inbound mail)',
                severity: FindingSeverity::Low,
                source: 'mail',
                category: 'mx',
                description: 'RFC 7505 null MX indicates the domain does not accept mail. Ensure SPF `-all` and DMARC `p=reject` to block spoofing.',
                evidence: ['domain' => $domain, 'mx' => $mx],
                fingerprint: 'mail-mx-null-'.$asset->id,
            );

            return $findings;
        }

        $findings[] = new ScannerFinding(
            title: 'MX records present',
            severity: FindingSeverity::Low,
            source: 'mail',
            category: 'mx',
            description: 'Mail exchangers: '.collect($mx)
                ->map(fn (array $row) => "{$row['priority']} {$row['host']}")
                ->implode(', '),
            evidence: ['domain' => $domain, 'mx' => $mx],
            fingerprint: 'mail-mx-present-'.$asset->id,
        );

        if (count($mx) === 1) {
            $findings[] = new ScannerFinding(
                title: 'Single MX host (no redundancy)',
                severity: FindingSeverity::Low,
                source: 'mail',
                category: 'mx',
                description: 'Only one MX target is published. A secondary MX improves delivery resilience.',
                evidence: ['mx' => $mx],
                fingerprint: 'mail-mx-single-'.$asset->id,
            );
        }

        foreach ($mx as $row) {
            $host = $row['host'];

            if ($host === '' || $host === '.') {
                continue;
            }

            $ips = $this->resolveHostIps($host);

            if ($ips === []) {
                $findings[] = new ScannerFinding(
                    title: "MX host does not resolve: {$host}",
                    severity: FindingSeverity::High,
                    source: 'mail',
                    category: 'mx',
                    description: "MX {$row['priority']} {$host} has no A/AAAA records — inbound mail will bounce or delay.",
                    evidence: ['mx_host' => $host, 'priority' => $row['priority'], 'ips' => []],
                    fingerprint: 'mail-mx-unresolved-'.$asset->id.'-'.$host,
                );

                continue;
            }

            foreach ($ips as $ip) {
                if ($this->isPrivateOrReservedIp($ip)) {
                    $findings[] = new ScannerFinding(
                        title: "MX resolves to private/reserved IP: {$host}",
                        severity: FindingSeverity::High,
                        source: 'mail',
                        category: 'mx',
                        description: "{$host} → {$ip}. Public MTAs cannot deliver to private or reserved addresses.",
                        evidence: ['mx_host' => $host, 'ip' => $ip, 'ips' => $ips],
                        fingerprint: 'mail-mx-private-'.$asset->id.'-'.$host.'-'.$ip,
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function lookupSpfRecords(string $domain): array
    {
        return array_values(array_filter(
            $this->lookupTxtRecords($domain),
            static fn (string $txt): bool => str_starts_with(strtolower($txt), 'v=spf1')
        ));
    }

    /**
     * @param  list<string>  $spf
     * @param  list<array{priority: int, host: string}>  $mx
     * @return list<ScannerFinding>
     */
    private function analyzeSpf(Asset $asset, string $domain, array $spf, array $mx): array
    {
        $findings = [];
        $acceptsMail = $this->acceptsMail($mx);

        if ($spf === []) {
            $findings[] = new ScannerFinding(
                title: 'SPF record missing',
                severity: $acceptsMail ? FindingSeverity::High : FindingSeverity::Medium,
                source: 'mail',
                category: 'spf',
                description: "No TXT `v=spf1` on {$domain}. Without SPF, receivers cannot validate authorized senders and spoofing is easier.",
                evidence: ['domain' => $domain],
                fingerprint: 'mail-spf-missing-'.$asset->id,
            );

            return $findings;
        }

        if (count($spf) > 1) {
            $findings[] = new ScannerFinding(
                title: 'Multiple SPF records published',
                severity: FindingSeverity::High,
                source: 'mail',
                category: 'spf',
                description: 'RFC 7208 allows only one SPF TXT string. Multiple records often cause SPF to fail permanently (PermError).',
                evidence: ['records' => $spf],
                fingerprint: 'mail-spf-multiple-'.$asset->id,
            );
        }

        foreach ($spf as $index => $record) {
            $normalized = preg_replace('/\s+/', ' ', trim($record)) ?? $record;
            $lower = strtolower($normalized);

            $findings[] = new ScannerFinding(
                title: 'SPF record found',
                severity: FindingSeverity::Low,
                source: 'mail',
                category: 'spf',
                description: $normalized,
                evidence: ['record' => $normalized],
                fingerprint: 'mail-spf-present-'.$asset->id.'-'.$index,
            );

            if (preg_match('/(?:^|\s)\+?all(?:\s|$)/', $lower) && ! preg_match('/(?:^|\s)[\~\-\?]all(?:\s|$)/', $lower)) {
                $findings[] = new ScannerFinding(
                    title: 'SPF ends with +all (or bare all)',
                    severity: FindingSeverity::High,
                    source: 'mail',
                    category: 'spf',
                    description: '`+all` authorizes any host to send as this domain — effectively disables SPF protection.',
                    evidence: ['record' => $normalized],
                    fingerprint: 'mail-spf-plus-all-'.$asset->id.'-'.$index,
                );
            } elseif (preg_match('/(?:^|\s)\?all(?:\s|$)/', $lower)) {
                $findings[] = new ScannerFinding(
                    title: 'SPF uses ?all (neutral)',
                    severity: FindingSeverity::Medium,
                    source: 'mail',
                    category: 'spf',
                    description: '`?all` treats unauthorized senders as neutral. Prefer `~all` (softfail) or ideally `-all` (fail).',
                    evidence: ['record' => $normalized],
                    fingerprint: 'mail-spf-neutral-all-'.$asset->id.'-'.$index,
                );
            } elseif (preg_match('/(?:^|\s)~all(?:\s|$)/', $lower)) {
                $findings[] = new ScannerFinding(
                    title: 'SPF uses ~all (softfail)',
                    severity: FindingSeverity::Low,
                    source: 'mail',
                    category: 'spf',
                    description: 'Softfail is common during rollout. Harden to `-all` once all legitimate senders are covered.',
                    evidence: ['record' => $normalized],
                    fingerprint: 'mail-spf-softfail-'.$asset->id.'-'.$index,
                );
            } elseif (preg_match('/(?:^|\s)-all(?:\s|$)/', $lower)) {
                // healthy — no extra finding beyond presence
            } else {
                $findings[] = new ScannerFinding(
                    title: 'SPF missing explicit all mechanism',
                    severity: FindingSeverity::Medium,
                    source: 'mail',
                    category: 'spf',
                    description: 'SPF records should end with `-all`, `~all`, or `?all`. Without it, evaluation may be incomplete.',
                    evidence: ['record' => $normalized],
                    fingerprint: 'mail-spf-no-all-'.$asset->id.'-'.$index,
                );
            }

            if (preg_match('/(?:^|\s)\+?ptr(?:[:\s]|$)/', $lower)) {
                $findings[] = new ScannerFinding(
                    title: 'SPF uses deprecated ptr mechanism',
                    severity: FindingSeverity::Medium,
                    source: 'mail',
                    category: 'spf',
                    description: '`ptr` is slow, unreliable, and discouraged by RFC 7208. Prefer `ip4`/`ip6`/`include`/`a`/`mx`.',
                    evidence: ['record' => $normalized],
                    fingerprint: 'mail-spf-ptr-'.$asset->id.'-'.$index,
                );
            }

            preg_match_all('/\b(?:include|a|mx|ptr|exists|redirect):/i', $normalized, $lookups);
            $lookupCount = count($lookups[0] ?? []);

            if ($lookupCount > 10) {
                $findings[] = new ScannerFinding(
                    title: 'SPF may exceed 10 DNS-lookup limit',
                    severity: FindingSeverity::Medium,
                    source: 'mail',
                    category: 'spf',
                    description: "Counted {$lookupCount} lookup-causing mechanisms. RFC 7208 caps recursive DNS lookups at 10 (PermError beyond that).",
                    evidence: ['record' => $normalized, 'lookup_mechanisms' => $lookupCount],
                    fingerprint: 'mail-spf-lookups-'.$asset->id.'-'.$index,
                );
            }
        }

        return $findings;
    }

    /**
     * @return array<string, string> selector => TXT record
     */
    private function discoverDkim(string $domain): array
    {
        $found = [];

        foreach ($this->commonDkimSelectors as $selector) {
            $host = "{$selector}._domainkey.{$domain}";
            foreach ($this->lookupTxtRecords($host) as $txt) {
                if ($this->looksLikeDkim($txt)) {
                    $found[$selector] = $txt;
                    break;
                }
            }
        }

        // Catch custom selectors advertised at _domainkey apex (rare but cheap).
        foreach ($this->lookupTxtRecords('_domainkey.'.$domain) as $txt) {
            if ($this->looksLikeDkim($txt) && ! in_array($txt, $found, true)) {
                $found['_domainkey'] = $txt;
            }
        }

        return $found;
    }

    private function looksLikeDkim(string $txt): bool
    {
        $lower = strtolower($txt);

        return str_contains($lower, 'v=dkim1') || str_contains($lower, 'p=');
    }

    /**
     * @param  array<string, string>  $dkim
     * @param  list<array{priority: int, host: string}>  $mx
     * @return list<ScannerFinding>
     */
    private function analyzeDkim(Asset $asset, string $domain, array $dkim, array $mx): array
    {
        $findings = [];
        $acceptsMail = $this->acceptsMail($mx);

        if ($dkim === []) {
            $findings[] = new ScannerFinding(
                title: 'No DKIM keys found on common selectors',
                severity: $acceptsMail ? FindingSeverity::Medium : FindingSeverity::Low,
                source: 'mail',
                category: 'dkim',
                description: 'Checked common selectors (google, selector1/2, default, …). Missing DKIM weakens authentication and DMARC alignment for outbound mail.',
                evidence: [
                    'domain' => $domain,
                    'selectors_checked' => $this->commonDkimSelectors,
                ],
                fingerprint: 'mail-dkim-missing-'.$asset->id,
            );

            return $findings;
        }

        $revokedSelectors = [];
        $active = [];

        foreach ($dkim as $selector => $record) {
            $normalized = preg_replace('/\s+/', ' ', trim($record)) ?? $record;

            if (preg_match('/(?:^|;)\s*p=\s*(?:;|$)/i', $normalized)) {
                $revokedSelectors[$selector] = $normalized;

                continue;
            }

            $active[$selector] = $normalized;
        }

        if ($revokedSelectors !== []) {
            $selectors = array_keys($revokedSelectors);
            $wildcardLikely = count($selectors) >= 3
                && count(array_unique(array_values($revokedSelectors))) === 1;

            $findings[] = new ScannerFinding(
                title: $wildcardLikely
                    ? 'DKIM wildcard publishes empty/revoked keys'
                    : 'DKIM public key revoked/empty',
                severity: FindingSeverity::Medium,
                source: 'mail',
                category: 'dkim',
                description: $wildcardLikely
                    ? 'Multiple selectors resolve to the same empty `p=` record (likely `*._domainkey` wildcard). Outbound DKIM using these selectors will fail.'
                    : 'Empty `p=` means the key is revoked. Outbound mail using this selector will fail DKIM.',
                evidence: [
                    'selectors' => $selectors,
                    'records' => $revokedSelectors,
                    'wildcard_likely' => $wildcardLikely,
                ],
                fingerprint: 'mail-dkim-revoked-'.$asset->id,
            );
        }

        foreach ($active as $selector => $normalized) {
            $lower = strtolower($normalized);

            $findings[] = new ScannerFinding(
                title: "DKIM key found (selector: {$selector})",
                severity: FindingSeverity::Low,
                source: 'mail',
                category: 'dkim',
                description: "Published at {$selector}._domainkey.{$domain}.",
                evidence: [
                    'selector' => $selector,
                    'host' => "{$selector}._domainkey.{$domain}",
                    'record' => $normalized,
                ],
                fingerprint: 'mail-dkim-present-'.$asset->id.'-'.$selector,
            );

            if (! str_contains($lower, 'v=dkim1')) {
                $findings[] = new ScannerFinding(
                    title: "DKIM record missing v=DKIM1 ({$selector})",
                    severity: FindingSeverity::Low,
                    source: 'mail',
                    category: 'dkim',
                    description: 'Records should start with `v=DKIM1` for clarity and interoperability.',
                    evidence: ['selector' => $selector, 'record' => $normalized],
                    fingerprint: 'mail-dkim-no-version-'.$asset->id.'-'.$selector,
                );
            }

            if (preg_match('/(?:^|;)\s*p=([A-Za-z0-9+\/=]+)/i', $normalized, $m)) {
                $keyType = strtolower((string) ($this->parseTagList($normalized)['k'] ?? 'rsa'));
                $key = $m[1];
                $approxBits = (int) (strlen($key) * 6);

                if ($keyType === 'ed25519' || $keyType === 'ed25519-sha256') {
                    // Compact keys are expected for ed25519 — do not flag length.
                } elseif ($approxBits > 0 && $approxBits < 1024) {
                    $findings[] = new ScannerFinding(
                        title: "DKIM key appears weak ({$selector})",
                        severity: FindingSeverity::High,
                        source: 'mail',
                        category: 'dkim',
                        description: "Public key payload looks under ~1024 bits (approx {$approxBits}). Use at least 2048-bit RSA (or ed25519).",
                        evidence: [
                            'selector' => $selector,
                            'k' => $keyType,
                            'approx_bits' => $approxBits,
                            'key_length_chars' => strlen($key),
                        ],
                        fingerprint: 'mail-dkim-weak-'.$asset->id.'-'.$selector,
                    );
                } elseif ($approxBits > 0 && $approxBits < 2048) {
                    $findings[] = new ScannerFinding(
                        title: "DKIM key under 2048 bits ({$selector})",
                        severity: FindingSeverity::Medium,
                        source: 'mail',
                        category: 'dkim',
                        description: "Estimated ~{$approxBits} bits. Prefer 2048-bit RSA keys for modern receivers.",
                        evidence: [
                            'selector' => $selector,
                            'k' => $keyType,
                            'approx_bits' => $approxBits,
                            'key_length_chars' => strlen($key),
                        ],
                        fingerprint: 'mail-dkim-short-'.$asset->id.'-'.$selector,
                    );
                }
            }

            if (preg_match('/(?:^|;)\s*t=([a-z:]+)/i', $normalized, $flags)) {
                $t = strtolower($flags[1]);
                if (str_contains($t, 'y')) {
                    $findings[] = new ScannerFinding(
                        title: "DKIM testing mode enabled ({$selector})",
                        severity: FindingSeverity::Low,
                        source: 'mail',
                        category: 'dkim',
                        description: '`t=y` tells receivers the domain is testing DKIM. Remove it in production.',
                        evidence: ['selector' => $selector, 't' => $t],
                        fingerprint: 'mail-dkim-testing-'.$asset->id.'-'.$selector,
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @param  list<string>  $dmarc
     * @param  list<array{priority: int, host: string}>  $mx
     * @return list<ScannerFinding>
     */
    private function analyzeDmarc(Asset $asset, string $domain, array $dmarc, array $mx): array
    {
        $findings = [];
        $acceptsMail = $this->acceptsMail($mx);
        $records = array_values(array_filter(
            $dmarc,
            static fn (string $txt): bool => str_starts_with(strtolower($txt), 'v=dmarc1')
        ));

        if ($records === []) {
            $findings[] = new ScannerFinding(
                title: 'DMARC record missing',
                severity: FindingSeverity::High,
                source: 'mail',
                category: 'dmarc',
                description: "No `_dmarc.{$domain}` TXT with `v=DMARC1`. Without DMARC, spoofed From: addresses are hard for receivers to reject consistently."
                    .($acceptsMail ? '' : ' Even domains without inbound mail should publish DMARC to prevent spoofing.'),
                evidence: ['host' => '_dmarc.'.$domain, 'txt' => $dmarc],
                fingerprint: 'mail-dmarc-missing-'.$asset->id,
            );

            return $findings;
        }

        if (count($records) > 1) {
            $findings[] = new ScannerFinding(
                title: 'Multiple DMARC records published',
                severity: FindingSeverity::High,
                source: 'mail',
                category: 'dmarc',
                description: 'Only one DMARC record should exist at `_dmarc`. Multiple records cause evaluation failures.',
                evidence: ['records' => $records],
                fingerprint: 'mail-dmarc-multiple-'.$asset->id,
            );
        }

        foreach ($records as $index => $record) {
            $normalized = preg_replace('/\s+/', ' ', trim($record)) ?? $record;
            $tags = $this->parseTagList($normalized);

            $findings[] = new ScannerFinding(
                title: 'DMARC record found',
                severity: FindingSeverity::Low,
                source: 'mail',
                category: 'dmarc',
                description: $normalized,
                evidence: ['record' => $normalized, 'tags' => $tags],
                fingerprint: 'mail-dmarc-present-'.$asset->id.'-'.$index,
            );

            $policy = strtolower((string) ($tags['p'] ?? ''));

            if ($policy === '') {
                $findings[] = new ScannerFinding(
                    title: 'DMARC missing required p= policy',
                    severity: FindingSeverity::High,
                    source: 'mail',
                    category: 'dmarc',
                    description: 'DMARC records must include `p=none|quarantine|reject`.',
                    evidence: ['record' => $normalized],
                    fingerprint: 'mail-dmarc-no-policy-'.$asset->id.'-'.$index,
                );
            } elseif ($policy === 'none') {
                $findings[] = new ScannerFinding(
                    title: 'DMARC policy is p=none (monitor only)',
                    severity: FindingSeverity::Medium,
                    source: 'mail',
                    category: 'dmarc',
                    description: '`p=none` collects reports but does not ask receivers to quarantine/reject failures. Move to `quarantine` then `reject`.',
                    evidence: ['record' => $normalized, 'p' => $policy],
                    fingerprint: 'mail-dmarc-none-'.$asset->id.'-'.$index,
                );
            } elseif ($policy === 'quarantine') {
                $findings[] = new ScannerFinding(
                    title: 'DMARC policy is p=quarantine',
                    severity: FindingSeverity::Low,
                    source: 'mail',
                    category: 'dmarc',
                    description: 'Good intermediate posture. `p=reject` is the strongest anti-spoofing setting once alignment is solid.',
                    evidence: ['record' => $normalized, 'p' => $policy],
                    fingerprint: 'mail-dmarc-quarantine-'.$asset->id.'-'.$index,
                );
            }

            if (! isset($tags['rua']) || trim((string) $tags['rua']) === '') {
                $findings[] = new ScannerFinding(
                    title: 'DMARC missing rua aggregate reports',
                    severity: FindingSeverity::Low,
                    source: 'mail',
                    category: 'dmarc',
                    description: 'Add `rua=mailto:…` (or HTTPS) to receive aggregate failure reports and tune policy safely.',
                    evidence: ['record' => $normalized],
                    fingerprint: 'mail-dmarc-no-rua-'.$asset->id.'-'.$index,
                );
            }

            if (isset($tags['pct']) && is_numeric($tags['pct']) && (int) $tags['pct'] < 100) {
                $findings[] = new ScannerFinding(
                    title: 'DMARC pct is below 100',
                    severity: FindingSeverity::Low,
                    source: 'mail',
                    category: 'dmarc',
                    description: "Only {$tags['pct']}% of failing messages get the policy applied. Raise to 100 when ready.",
                    evidence: ['record' => $normalized, 'pct' => $tags['pct']],
                    fingerprint: 'mail-dmarc-pct-'.$asset->id.'-'.$index,
                );
            }

            $sp = strtolower((string) ($tags['sp'] ?? ''));
            if ($sp === 'none' && $policy !== 'none') {
                $findings[] = new ScannerFinding(
                    title: 'DMARC subdomain policy weaker than org policy',
                    severity: FindingSeverity::Medium,
                    source: 'mail',
                    category: 'dmarc',
                    description: '`sp=none` leaves subdomains in monitor-only mode while the organizational domain is stricter.',
                    evidence: ['record' => $normalized, 'p' => $policy, 'sp' => $sp],
                    fingerprint: 'mail-dmarc-sp-none-'.$asset->id.'-'.$index,
                );
            }
        }

        return $findings;
    }

    /**
     * @param  list<string>  $mtaSts
     * @param  list<array{priority: int, host: string}>  $mx
     * @return list<ScannerFinding>
     */
    private function analyzeMtaSts(Asset $asset, string $domain, array $mtaSts, array $mx): array
    {
        if (! $this->acceptsMail($mx)) {
            return [];
        }

        $findings = [];
        $records = array_values(array_filter(
            $mtaSts,
            static fn (string $txt): bool => str_contains(strtolower($txt), 'v=stsv1')
        ));

        if ($records === []) {
            $findings[] = new ScannerFinding(
                title: 'MTA-STS not published',
                severity: FindingSeverity::Low,
                source: 'mail',
                category: 'mta_sts',
                description: "No `_mta-sts.{$domain}` TXT (`v=STSv1`). MTA-STS helps enforce TLS for inbound SMTP and mitigate downgrade attacks.",
                evidence: ['host' => '_mta-sts.'.$domain],
                fingerprint: 'mail-mta-sts-missing-'.$asset->id,
            );

            return $findings;
        }

        foreach ($records as $index => $record) {
            $findings[] = new ScannerFinding(
                title: 'MTA-STS DNS record found',
                severity: FindingSeverity::Low,
                source: 'mail',
                category: 'mta_sts',
                description: trim($record),
                evidence: ['record' => $record],
                fingerprint: 'mail-mta-sts-dns-'.$asset->id.'-'.$index,
            );
        }

        if (! config('hackly.mail_security.check_mta_sts_policy', true)) {
            return $findings;
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders(['Accept' => 'text/plain'])
                ->get("https://mta-sts.{$domain}/.well-known/mta-sts.txt");

            if (! $response->successful()) {
                $findings[] = new ScannerFinding(
                    title: 'MTA-STS policy file unreachable',
                    severity: FindingSeverity::Medium,
                    source: 'mail',
                    category: 'mta_sts',
                    description: "DNS advertises MTA-STS but https://mta-sts.{$domain}/.well-known/mta-sts.txt returned HTTP {$response->status()}.",
                    evidence: ['status' => $response->status()],
                    fingerprint: 'mail-mta-sts-policy-http-'.$asset->id,
                );

                return $findings;
            }

            $body = trim($response->body());
            $policy = $this->parseMtaStsPolicy($body);

            $findings[] = new ScannerFinding(
                title: 'MTA-STS policy fetched',
                severity: FindingSeverity::Low,
                source: 'mail',
                category: 'mta_sts',
                description: 'Policy mode: '.($policy['mode'] ?? 'unknown'),
                evidence: ['policy' => $policy, 'raw' => substr($body, 0, 2000)],
                fingerprint: 'mail-mta-sts-policy-'.$asset->id,
            );

            $mode = strtolower((string) ($policy['mode'] ?? ''));
            if ($mode === 'none' || $mode === 'testing') {
                $findings[] = new ScannerFinding(
                    title: "MTA-STS mode is {$mode}",
                    severity: FindingSeverity::Medium,
                    source: 'mail',
                    category: 'mta_sts',
                    description: 'Set `mode: enforce` once TLS on all MX hosts is verified.',
                    evidence: ['policy' => $policy],
                    fingerprint: 'mail-mta-sts-mode-'.$asset->id,
                );
            }
        } catch (\Throwable $e) {
            $findings[] = new ScannerFinding(
                title: 'MTA-STS policy fetch failed',
                severity: FindingSeverity::Medium,
                source: 'mail',
                category: 'mta_sts',
                description: $e->getMessage(),
                evidence: ['error' => $e->getMessage()],
                fingerprint: 'mail-mta-sts-policy-error-'.$asset->id,
            );
        }

        return $findings;
    }

    /**
     * @param  list<string>  $tlsRpt
     * @param  list<array{priority: int, host: string}>  $mx
     * @return list<ScannerFinding>
     */
    private function analyzeTlsRpt(Asset $asset, string $domain, array $tlsRpt, array $mx): array
    {
        if (! $this->acceptsMail($mx)) {
            return [];
        }

        $records = array_values(array_filter(
            $tlsRpt,
            static fn (string $txt): bool => str_contains(strtolower($txt), 'v=tlsrpt1')
        ));

        if ($records === []) {
            return [
                new ScannerFinding(
                    title: 'SMTP TLS reporting (TLS-RPT) missing',
                    severity: FindingSeverity::Low,
                    source: 'mail',
                    category: 'tlsrpt',
                    description: "No `_smtp._tls.{$domain}` TXT (`v=TLSRPTv1`). TLS-RPT reports help detect STARTTLS failures and downgrade attempts.",
                    evidence: ['host' => '_smtp._tls.'.$domain],
                    fingerprint: 'mail-tlsrpt-missing-'.$asset->id,
                ),
            ];
        }

        return [
            new ScannerFinding(
                title: 'TLS-RPT record found',
                severity: FindingSeverity::Low,
                source: 'mail',
                category: 'tlsrpt',
                description: $records[0],
                evidence: ['records' => $records],
                fingerprint: 'mail-tlsrpt-present-'.$asset->id,
            ),
        ];
    }

    /**
     * @param  list<string>  $bimi
     * @param  list<string>  $dmarc
     * @return list<ScannerFinding>
     */
    private function analyzeBimi(Asset $asset, string $domain, array $bimi, array $dmarc): array
    {
        $records = array_values(array_filter(
            $bimi,
            static fn (string $txt): bool => str_contains(strtolower($txt), 'v=bimi1')
        ));

        if ($records === []) {
            return [];
        }

        $findings = [
            new ScannerFinding(
                title: 'BIMI record found',
                severity: FindingSeverity::Low,
                source: 'mail',
                category: 'bimi',
                description: $records[0],
                evidence: ['records' => $records],
                fingerprint: 'mail-bimi-present-'.$asset->id,
            ),
        ];

        $dmarcPolicy = null;
        foreach ($dmarc as $txt) {
            if (! str_starts_with(strtolower($txt), 'v=dmarc1')) {
                continue;
            }
            $tags = $this->parseTagList($txt);
            $dmarcPolicy = strtolower((string) ($tags['p'] ?? ''));
            break;
        }

        if (! in_array($dmarcPolicy, ['quarantine', 'reject'], true)) {
            $findings[] = new ScannerFinding(
                title: 'BIMI without enforcing DMARC',
                severity: FindingSeverity::Medium,
                source: 'mail',
                category: 'bimi',
                description: 'BIMI generally requires DMARC `p=quarantine` or `p=reject` (and often a verified mark certificate) to display logos.',
                evidence: ['dmarc_p' => $dmarcPolicy, 'bimi' => $records],
                fingerprint: 'mail-bimi-weak-dmarc-'.$asset->id,
            );
        }

        return $findings;
    }

    /**
     * @param  list<array{priority: int, host: string}>  $mx
     */
    private function acceptsMail(array $mx): bool
    {
        if ($mx === []) {
            return false;
        }

        return ! collect($mx)->contains(
            fn (array $row): bool => $row['priority'] === 0 && in_array($row['host'], ['.', ''], true)
        );
    }

    /**
     * @return list<string>
     */
    private function lookupTxtRecords(string $name): array
    {
        $runner = app(BinaryRunner::class);
        $dig = $this->binary('dig');

        try {
            $result = $runner->run([$dig, '+short', 'TXT', $name], 10);
        } catch (\Throwable) {
            return [];
        }

        $records = [];

        foreach (preg_split('/\r\n|\r|\n/', $result->stdout) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // dig +short TXT may split long strings: "foo" "bar"
            if (preg_match_all('/"([^"]*)"/', $line, $parts)) {
                $records[] = implode('', $parts[1]);

                continue;
            }

            $records[] = trim($line, '"');
        }

        return array_values(array_filter($records, static fn (string $v): bool => $v !== ''));
    }

    /**
     * @return list<string>
     */
    private function resolveHostIps(string $host): array
    {
        $runner = app(BinaryRunner::class);
        $dig = $this->binary('dig');
        $ips = [];

        foreach (['A', 'AAAA'] as $type) {
            try {
                $result = $runner->run([$dig, '+short', $type, $host], 10);
            } catch (\Throwable) {
                continue;
            }

            foreach (preg_split('/\r\n|\r|\n/', $result->stdout) ?: [] as $line) {
                $line = rtrim(trim($line), '.');
                if (filter_var($line, FILTER_VALIDATE_IP) !== false) {
                    $ips[] = $line;
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function isPrivateOrReservedIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /**
     * @return array<string, string>
     */
    private function parseTagList(string $record): array
    {
        $tags = [];

        foreach (explode(';', $record) as $part) {
            $part = trim($part);
            if ($part === '' || ! str_contains($part, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $part, 2));
            $tags[strtolower($key)] = $value;
        }

        return $tags;
    }

    /**
     * @return array<string, string>
     */
    private function parseMtaStsPolicy(string $body): array
    {
        $policy = [];

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $policy[strtolower($key)] = $value;
        }

        return $policy;
    }
}
