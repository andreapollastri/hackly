<?php

namespace App\Domain\Scanning\Support;

/**
 * Nuclei writes config/cache under $HOME. Queue workers often have an unwritable
 * HOME or CWD (app root), which breaks production scans.
 */
final class NucleiRuntime
{
    public static function home(): string
    {
        $home = (string) config('hackly.nuclei.home', storage_path('app/nuclei-home'));

        self::ensureDirectory($home);

        foreach (['.config/nuclei', '.cache/nuclei', 'nuclei-templates'] as $subdir) {
            self::ensureDirectory($home.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $subdir));
        }

        return $home;
    }

    /**
     * Create the nuclei HOME tree and best-effort chown for the web/queue user.
     *
     * @return array{home: string, owner: ?string, chowned: bool}
     */
    public static function provision(?string $owner = null): array
    {
        $home = self::home();
        $owner = $owner
            ?? env('HACKLY_STORAGE_OWNER')
            ?? self::detectWebUser();

        $chowned = false;

        if ($owner !== null) {
            $chowned = self::chownRecursive($home, $owner);
        }

        return [
            'home' => $home,
            'owner' => $owner,
            'chowned' => $chowned,
        ];
    }

    public static function templatesDirectory(): string
    {
        $configured = config('hackly.nuclei.templates_path');

        if (filled($configured)) {
            return (string) $configured;
        }

        return self::home().DIRECTORY_SEPARATOR.'nuclei-templates';
    }

    /**
     * @return array<string, string>
     */
    public static function environment(): array
    {
        $home = self::home();

        return [
            'HOME' => $home,
            'XDG_CONFIG_HOME' => $home.DIRECTORY_SEPARATOR.'.config',
            'XDG_CACHE_HOME' => $home.DIRECTORY_SEPARATOR.'.cache',
            // Avoid interactive / cloud auth prompts in workers.
            'PDCP_DISABLE_UPDATE_CHECK' => 'true',
        ];
    }

    /**
     * Common CLI flags for non-interactive worker runs.
     *
     * @return list<string>
     */
    public static function baseFlags(): array
    {
        return [
            '-duc',
            '-nc',
            '-ud',
            self::templatesDirectory(),
        ];
    }

    private static function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }

        @chmod($path, 0775);
    }

    private static function detectWebUser(): ?string
    {
        foreach (['www-data', 'nginx', 'apache', 'http', 'cipi'] as $user) {
            if (function_exists('posix_getpwnam') && posix_getpwnam($user) !== false) {
                return $user;
            }
        }

        return null;
    }

    private static function chownRecursive(string $path, string $owner): bool
    {
        if (! function_exists('chown') || ! function_exists('posix_getpwnam')) {
            return false;
        }

        $info = posix_getpwnam($owner);

        if ($info === false) {
            return false;
        }

        $uid = $info['uid'];
        $gid = $info['gid'];
        $ok = true;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        if (! @chown($path, $uid) || ! @chgrp($path, $gid)) {
            $ok = false;
        }

        foreach ($iterator as $file) {
            $pathname = $file->getPathname();

            if (! @chown($pathname, $uid) || ! @chgrp($pathname, $gid)) {
                $ok = false;
            }
        }

        return $ok;
    }
}
