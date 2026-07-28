<?php

namespace App\Domain\Scanning\Services;

use App\Models\Asset;
use InvalidArgumentException;

class TargetGuard
{
    public function assertAllowed(Asset $asset): void
    {
        $value = trim($asset->value);

        if ($value === '') {
            throw new InvalidArgumentException('Asset value is empty.');
        }

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException(
                'Raw IP targets are not supported. Enter a domain; Hackly resolves and checks its A/AAAA addresses.'
            );
        }

        $this->assertValidDomain($value);

        if (config('hackly.allowlist_only')) {
            $allowlist = array_map('strtolower', config('hackly.allowlist', []));

            if (! in_array(strtolower($value), $allowlist, true)) {
                throw new InvalidArgumentException("Target [{$value}] is not in the allowlist.");
            }
        }

        if (config('hackly.allow_private_targets')) {
            return;
        }

        if ($this->isLocalHostname($value)) {
            throw new InvalidArgumentException("Private/local targets are blocked: {$value}");
        }

        foreach ($this->resolvePublicFacingIps($value) as $ip) {
            if ($this->isPrivateOrReservedIp($ip)) {
                throw new InvalidArgumentException("Private/local targets are blocked: {$value} → {$ip}");
            }
        }
    }

    private function assertValidDomain(string $value): void
    {
        if (! preg_match('/^(?=.{1,253}$)(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))+$/', $value)) {
            throw new InvalidArgumentException("Invalid domain: {$value}");
        }
    }

    private function isLocalHostname(string $value): bool
    {
        return in_array(strtolower($value), ['localhost', 'localhost.localdomain'], true);
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
     * Resolve A + AAAA for the domain (same IPs scanners will hit).
     *
     * @return list<string>
     */
    public function resolvePublicFacingIps(string $domain): array
    {
        $ips = [];

        if (function_exists('dns_get_record')) {
            foreach ([DNS_A, DNS_AAAA] as $type) {
                $records = @dns_get_record($domain, $type);

                if (! is_array($records)) {
                    continue;
                }

                foreach ($records as $record) {
                    $ip = $record['ip'] ?? $record['ipv6'] ?? null;

                    if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                        $ips[] = $ip;
                    }
                }
            }
        }

        if ($ips === []) {
            $resolved = gethostbyname($domain);

            if ($resolved !== $domain && filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
                $ips[] = $resolved;
            }
        }

        return array_values(array_unique($ips));
    }
}
