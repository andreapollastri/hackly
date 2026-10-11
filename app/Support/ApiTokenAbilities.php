<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Personal access tokens are per user. Abilities are expanded so a write token
 * can also read, while only a full token (literal "*") may mint or revoke tokens.
 */
class ApiTokenAbilities
{
    public const FULL = '*';

    public const READ = 'read';

    public const WRITE = 'write';

    /**
     * @return list<string>
     */
    public static function choices(): array
    {
        return [self::FULL, self::READ, self::WRITE];
    }

    /**
     * @return list<string>
     */
    public static function expand(string $choice): array
    {
        return match ($choice) {
            self::READ => [self::READ],
            self::WRITE => [self::READ, self::WRITE],
            self::FULL => [self::FULL],
            default => throw new InvalidArgumentException('Ability must be *, read, or write.'),
        };
    }

    /**
     * @param  list<string>  $abilities
     */
    public static function label(array $abilities): string
    {
        if (in_array(self::FULL, $abilities, true)) {
            return 'Full access';
        }

        if (in_array(self::WRITE, $abilities, true)) {
            return 'Read and write';
        }

        return 'Read only';
    }
}
