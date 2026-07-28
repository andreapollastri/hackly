<?php

namespace App\Domain\Scanning\Services;

use App\Enums\AssetType;
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

        if ($asset->type === AssetType::Ip) {
            $this->assertValidIp($value);
        } else {
            $this->assertValidDomain($value);
        }

        if (config('hackly.allowlist_only')) {
            $allowlist = array_map('strtolower', config('hackly.allowlist', []));

            if (! in_array(strtolower($value), $allowlist, true)) {
                throw new InvalidArgumentException("Target [{$value}] is not in the allowlist.");
            }
        }

        if (! config('hackly.allow_private_targets') && $this->isPrivateOrLocal($value, $asset->type)) {
            throw new InvalidArgumentException("Private/local targets are blocked: {$value}");
        }
    }

    private function assertValidIp(string $value): void
    {
        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException("Invalid IP address: {$value}");
        }
    }

    private function assertValidDomain(string $value): void
    {
        if (! preg_match('/^(?=.{1,253}$)(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))+$/', $value)) {
            throw new InvalidArgumentException("Invalid domain: {$value}");
        }
    }

    private function isPrivateOrLocal(string $value, AssetType $type): bool
    {
        if (in_array(strtolower($value), ['localhost', 'localhost.localdomain'], true)) {
            return true;
        }

        if ($type === AssetType::Ip) {
            return ! filter_var(
                $value,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        $resolved = gethostbyname($value);

        if ($resolved === $value) {
            return false;
        }

        return ! filter_var(
            $resolved,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
