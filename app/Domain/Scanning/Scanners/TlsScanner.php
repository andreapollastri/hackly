<?php

namespace App\Domain\Scanning\Scanners;

use App\Domain\Scanning\DTO\BinaryResult;
use App\Domain\Scanning\DTO\ScannerFinding;
use App\Enums\FindingSeverity;
use App\Enums\ScanTaskType;
use App\Models\Asset;
use App\Models\ScanTask;

class TlsScanner extends AbstractScanner
{
    public function type(): ScanTaskType
    {
        return ScanTaskType::TlsCheck;
    }

    public function timeoutSeconds(): int
    {
        return (int) config('hackly.tls.timeout', 90);
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
            'A',
            $asset->value,
        ];
    }

    public function parse(Asset $asset, ScanTask $task, BinaryResult $result): array
    {
        $host = strtolower(trim($asset->value));
        $port = 443;
        $findings = [];

        $certInfo = $this->fetchPeerCertificate($host, $port);

        if ($certInfo === null) {
            return [
                new ScannerFinding(
                    title: 'TLS handshake failed on :443',
                    severity: FindingSeverity::High,
                    source: 'tls',
                    category: 'tls',
                    description: "Could not complete a TLS handshake to {$host}:{$port}. HTTPS may be misconfigured or filtered.",
                    evidence: ['host' => $host, 'port' => $port],
                    fingerprint: 'tls-handshake-fail-'.$asset->id,
                ),
            ];
        }

        $findings = [
            ...$findings,
            ...$this->analyzeCertificate($asset, $host, $certInfo),
            ...$this->analyzeProtocols($asset, $host, $port),
        ];

        return $findings;
    }

    /**
     * @return array{
     *     parsed: array<string, mixed>,
     *     san: list<string>,
     *     valid_from: int,
     *     valid_to: int,
     *     issuer: string,
     *     subject: string,
     *     chain_length: int
     * }|null
     */
    private function fetchPeerCertificate(string $host, int $port): ?array
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'capture_peer_cert_chain' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $client = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($client === false) {
            return null;
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $certResource = $params['options']['ssl']['peer_certificate'] ?? null;

        if ($certResource === null) {
            return null;
        }

        $parsed = openssl_x509_parse($certResource);

        if (! is_array($parsed)) {
            return null;
        }

        $san = [];
        $sanRaw = $parsed['extensions']['subjectAltName'] ?? '';

        if (is_string($sanRaw) && $sanRaw !== '') {
            foreach (explode(',', $sanRaw) as $part) {
                $part = trim($part);
                if (str_starts_with(strtolower($part), 'dns:')) {
                    $san[] = strtolower(substr($part, 4));
                }
            }
        }

        $chain = $params['options']['ssl']['peer_certificate_chain'] ?? [];

        $issuer = '';
        if (isset($parsed['issuer']) && is_array($parsed['issuer'])) {
            $issuer = (string) ($parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? json_encode($parsed['issuer']));
        }

        $subject = '';
        if (isset($parsed['subject']) && is_array($parsed['subject'])) {
            $subject = (string) ($parsed['subject']['CN'] ?? json_encode($parsed['subject']));
        }

        return [
            'parsed' => $parsed,
            'san' => $san,
            'valid_from' => (int) ($parsed['validFrom_time_t'] ?? 0),
            'valid_to' => (int) ($parsed['validTo_time_t'] ?? 0),
            'issuer' => $issuer,
            'subject' => $subject,
            'chain_length' => is_array($chain) ? count($chain) : 0,
        ];
    }

    /**
     * @param  array{
     *     parsed: array<string, mixed>,
     *     san: list<string>,
     *     valid_from: int,
     *     valid_to: int,
     *     issuer: string,
     *     subject: string,
     *     chain_length: int
     * }  $cert
     * @return list<ScannerFinding>
     */
    private function analyzeCertificate(Asset $asset, string $host, array $cert): array
    {
        $findings = [];
        $now = time();
        $validTo = $cert['valid_to'];
        $daysLeft = $validTo > 0 ? (int) floor(($validTo - $now) / 86400) : -1;
        $expiresAt = $validTo > 0 ? gmdate('Y-m-d H:i:s', $validTo).' UTC' : 'unknown';

        $evidence = [
            'host' => $host,
            'subject' => $cert['subject'],
            'issuer' => $cert['issuer'],
            'san' => $cert['san'],
            'valid_from' => $cert['valid_from'] > 0 ? gmdate('Y-m-d H:i:s', $cert['valid_from']).' UTC' : null,
            'valid_to' => $expiresAt,
            'days_left' => $daysLeft,
            'chain_length' => $cert['chain_length'],
        ];

        if ($daysLeft < 0) {
            $findings[] = new ScannerFinding(
                title: 'TLS certificate expired',
                severity: FindingSeverity::High,
                source: 'tls',
                category: 'certificate',
                description: "Certificate for {$host} expired on {$expiresAt}. Browsers will block HTTPS.",
                evidence: $evidence,
                fingerprint: 'tls-cert-expired-'.$asset->id,
            );
        } elseif ($daysLeft <= 7) {
            $findings[] = new ScannerFinding(
                title: "TLS certificate expires in {$daysLeft} day(s)",
                severity: FindingSeverity::High,
                source: 'tls',
                category: 'certificate',
                description: "Certificate expires on {$expiresAt}. Renew immediately.",
                evidence: $evidence,
                fingerprint: 'tls-cert-expiry-'.$asset->id,
            );
        } elseif ($daysLeft <= 14) {
            $findings[] = new ScannerFinding(
                title: "TLS certificate expires in {$daysLeft} days",
                severity: FindingSeverity::High,
                source: 'tls',
                category: 'certificate',
                description: "Certificate expires on {$expiresAt}. Renew soon.",
                evidence: $evidence,
                fingerprint: 'tls-cert-expiry-'.$asset->id,
            );
        } elseif ($daysLeft <= 30) {
            $findings[] = new ScannerFinding(
                title: "TLS certificate expires in {$daysLeft} days",
                severity: FindingSeverity::Medium,
                source: 'tls',
                category: 'certificate',
                description: "Certificate expires on {$expiresAt}. Plan renewal within the next weeks.",
                evidence: $evidence,
                fingerprint: 'tls-cert-expiry-'.$asset->id,
            );
        } else {
            $findings[] = new ScannerFinding(
                title: 'TLS certificate validity OK',
                severity: FindingSeverity::Low,
                source: 'tls',
                category: 'passed',
                description: "Certificate valid until {$expiresAt} ({$daysLeft} days left). Issuer: {$cert['issuer']}.",
                evidence: $evidence,
                fingerprint: 'tls-cert-ok-'.$asset->id,
            );
        }

        if (! $this->hostnameMatchesCertificate($host, $cert)) {
            $findings[] = new ScannerFinding(
                title: 'TLS certificate hostname mismatch',
                severity: FindingSeverity::High,
                source: 'tls',
                category: 'certificate',
                description: "Certificate CN/SAN does not cover {$host}.",
                evidence: $evidence,
                fingerprint: 'tls-cert-hostname-'.$asset->id,
            );
        } else {
            $findings[] = new ScannerFinding(
                title: 'TLS certificate hostname matches',
                severity: FindingSeverity::Low,
                source: 'tls',
                category: 'passed',
                description: "CN/SAN covers {$host}.",
                evidence: ['host' => $host, 'san' => $cert['san'], 'subject' => $cert['subject']],
                fingerprint: 'tls-cert-hostname-ok-'.$asset->id,
            );
        }

        if ($cert['chain_length'] < 1) {
            $findings[] = new ScannerFinding(
                title: 'TLS certificate chain may be incomplete',
                severity: FindingSeverity::Medium,
                source: 'tls',
                category: 'certificate',
                description: 'Peer did not present intermediate certificates. Some clients may fail validation.',
                evidence: $evidence,
                fingerprint: 'tls-cert-chain-'.$asset->id,
            );
        }

        return $findings;
    }

    /**
     * @param  array{san: list<string>, subject: string}  $cert
     */
    private function hostnameMatchesCertificate(string $host, array $cert): bool
    {
        $candidates = $cert['san'];

        if ($cert['subject'] !== '') {
            $candidates[] = strtolower($cert['subject']);
        }

        foreach ($candidates as $name) {
            $name = strtolower(trim($name));

            if ($name === $host) {
                return true;
            }

            if (str_starts_with($name, '*.')) {
                $suffix = substr($name, 1); // .example.com
                if (str_ends_with($host, $suffix) && substr_count($host, '.') === substr_count(ltrim($suffix, '.'), '.') + 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<ScannerFinding>
     */
    private function analyzeProtocols(Asset $asset, string $host, int $port): array
    {
        $findings = [];
        $supported = [];

        $versions = [
            'TLS1.0' => defined('STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT : null,
            'TLS1.1' => defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT : null,
            'TLS1.2' => defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT : null,
            'TLS1.3' => defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : null,
        ];

        foreach ($versions as $label => $method) {
            if ($method === null) {
                continue;
            }

            if ($this->protocolSupported($host, $port, $method)) {
                $supported[] = $label;
            }
        }

        if ($supported === []) {
            return $findings;
        }

        $legacy = array_values(array_intersect($supported, ['TLS1.0', 'TLS1.1']));

        if ($legacy !== []) {
            $findings[] = new ScannerFinding(
                title: 'Legacy TLS protocols enabled ('.implode(', ', $legacy).')',
                severity: FindingSeverity::High,
                source: 'tls',
                category: 'protocol',
                description: 'TLS 1.0/1.1 are deprecated and should be disabled. Prefer TLS 1.2+ only.',
                evidence: ['host' => $host, 'supported' => $supported, 'legacy' => $legacy],
                fingerprint: 'tls-legacy-protocol-'.$asset->id,
            );
        } else {
            $findings[] = new ScannerFinding(
                title: 'Legacy TLS protocols disabled',
                severity: FindingSeverity::Low,
                source: 'tls',
                category: 'passed',
                description: 'TLS 1.0/1.1 not accepted. Supported: '.implode(', ', $supported),
                evidence: ['host' => $host, 'supported' => $supported],
                fingerprint: 'tls-legacy-protocol-ok-'.$asset->id,
            );
        }

        if (! in_array('TLS1.2', $supported, true) && ! in_array('TLS1.3', $supported, true)) {
            $findings[] = new ScannerFinding(
                title: 'Modern TLS (1.2+) not detected',
                severity: FindingSeverity::High,
                source: 'tls',
                category: 'protocol',
                description: 'Could not negotiate TLS 1.2 or 1.3.',
                evidence: ['host' => $host, 'supported' => $supported],
                fingerprint: 'tls-modern-missing-'.$asset->id,
            );
        }

        return $findings;
    }

    private function protocolSupported(string $host, int $port, int $cryptoMethod): bool
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
                'crypto_method' => $cryptoMethod,
            ],
        ]);

        $client = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            8,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($client === false) {
            return false;
        }

        fclose($client);

        return true;
    }
}
