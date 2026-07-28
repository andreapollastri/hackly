<?php

namespace App\Domain\Scanning\Services;

use App\Enums\AssetType;
use App\Models\Asset;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class DnsOwnershipVerifier
{
    public function issueToken(Asset $asset): string
    {
        if ($asset->type !== AssetType::Domain) {
            throw new InvalidArgumentException('DNS ownership verification is only available for domain targets.');
        }

        $token = 'hackly-verify='.Str::random(40);

        $asset->update([
            'verification_token' => $token,
            'verified_at' => null,
        ]);

        return $token;
    }

    public function verify(Asset $asset): bool
    {
        if ($asset->type === AssetType::Ip) {
            $asset->update(['verified_at' => now()]);

            return true;
        }

        $token = trim((string) $asset->verification_token);

        if ($token === '') {
            throw new InvalidArgumentException('Generate a verification token first, then publish it as a TXT record.');
        }

        $records = $this->lookupTxtRecords($asset->value);

        $matched = collect($records)->contains(
            fn (string $record) => str_contains($record, $token)
        );

        if (! $matched) {
            throw new RuntimeException(
                "TXT record not found for {$asset->value}. Publish `{$token}` as a DNS TXT record, wait for propagation, then verify again."
            );
        }

        $asset->update(['verified_at' => now()]);

        return true;
    }

    public function assertVerified(Asset $asset): void
    {
        if ($asset->verified_at !== null) {
            return;
        }

        if ($asset->type === AssetType::Domain) {
            throw new InvalidArgumentException(
                'Target is not DNS-verified. Publish the TXT token and run Verify DNS before starting a scan.'
            );
        }

        throw new InvalidArgumentException(
            'Target is not verified. Confirm ownership before starting a scan.'
        );
    }

    /**
     * @return list<string>
     */
    public function lookupTxtRecords(string $domain): array
    {
        $domain = trim($domain);

        if ($domain === '' || ! function_exists('dns_get_record')) {
            throw new RuntimeException('DNS TXT lookup failed: dns_get_record is not available.');
        }

        $raw = @dns_get_record($domain, DNS_TXT);

        if ($raw === false) {
            throw new RuntimeException("DNS TXT lookup failed for {$domain}.");
        }

        $records = [];

        foreach ($raw as $row) {
            if (! empty($row['entries']) && is_array($row['entries'])) {
                $records[] = implode('', $row['entries']);

                continue;
            }

            if (isset($row['txt']) && is_string($row['txt']) && $row['txt'] !== '') {
                $records[] = $row['txt'];
            }
        }

        return array_values($records);
    }
}
