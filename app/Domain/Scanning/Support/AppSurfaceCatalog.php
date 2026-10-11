<?php

namespace App\Domain\Scanning\Support;

use App\Models\Asset;
use App\Models\Repository;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Routes and stack hints learned from a linked repository, reused by DAST and penetration tests.
 */
class AppSurfaceCatalog
{
    public const MAX_PATHS = 40;

    public static function remember(Repository $repository, string $workspace, ?string $scanId = null): void
    {
        if (! is_dir($workspace)) {
            return;
        }

        $meta = $repository->meta ?? [];
        $meta['surface_paths'] = self::extract($workspace);
        $meta['surface_technology'] = self::technology($workspace);

        if ($scanId !== null) {
            $meta['surface_scan_id'] = $scanId;
        }

        $repository->forceFill(['meta' => $meta])->save();
    }

    /**
     * @return list<string>
     */
    public static function pathsFor(Asset $asset): array
    {
        $paths = [];

        foreach ($asset->repositories()->get(['repositories.id', 'repositories.meta']) as $repository) {
            foreach ($repository->meta['surface_paths'] ?? [] as $path) {
                if (is_string($path)) {
                    $paths[] = $path;
                }
            }
        }

        return array_slice(array_values(array_unique($paths)), 0, self::MAX_PATHS);
    }

    /**
     * @return list<string>
     */
    public static function extract(string $workspace): array
    {
        $paths = [];

        foreach (glob($workspace.'/routes/*.php') ?: [] as $file) {
            $contents = (string) file_get_contents($file);

            if (preg_match_all("/Route::(?:get|post|put|patch|delete|options|match|any|view)\\(\\s*['\"]([^'\"]+)['\"]/i", $contents, $matches) < 1) {
                continue;
            }

            foreach ($matches[1] as $path) {
                $normalized = self::normalize((string) $path);

                if ($normalized !== null) {
                    $paths[] = $normalized;
                }
            }
        }

        foreach (['app', 'src/app', 'pages', 'src/pages'] as $root) {
            $paths = [...$paths, ...self::pageRoutes($workspace.'/'.$root)];
        }

        return array_slice(array_values(array_unique($paths)), 0, self::MAX_PATHS);
    }

    public static function technology(string $workspace): ?string
    {
        if (is_file($workspace.'/artisan') || self::composerRequires($workspace, 'laravel/framework')) {
            return 'laravel';
        }

        if (is_file($workspace.'/wp-config.php') || is_dir($workspace.'/wp-content')) {
            return 'wordpress';
        }

        $package = self::readJson($workspace.'/package.json');
        $dependencies = array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? []);

        if (isset($dependencies['next'])) {
            return 'next';
        }

        if (isset($dependencies['react']) || isset($dependencies['vue']) || isset($dependencies['express'])) {
            return 'node';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function pageRoutes(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $paths = [];
        $seen = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (++$seen > 2000 || ! $file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($directory, '/')) + 1));

            if (str_contains($relative, 'node_modules/') || str_contains($relative, '[')) {
                continue;
            }

            $isAppRouter = basename($directory) === 'app';

            if ($isAppRouter && preg_match('#(?:^|/)page\.(tsx|jsx|ts|js)$#', $relative) === 1) {
                $route = dirname('/'.$relative);
            } elseif (! $isAppRouter && preg_match('#\.(tsx|jsx|js|ts)$#', $relative) === 1) {
                $route = '/'.preg_replace('#\.(tsx|jsx|ts|js)$#', '', $relative);
                $route = preg_replace('#/index$#', '', (string) $route) ?: '/';
            } else {
                continue;
            }

            $normalized = self::normalize(is_string($route) ? $route : '');

            if ($normalized !== null && $normalized !== '/') {
                $paths[] = $normalized;
            }
        }

        return $paths;
    }

    private static function normalize(string $path): ?string
    {
        $path = '/'.ltrim(trim($path), '/');

        if ($path === '/' || str_contains($path, '..') || str_contains($path, '{') || str_contains($path, '$') || strlen($path) > 120) {
            return null;
        }

        if (preg_match('#^/[A-Za-z0-9/_\-.]+$#', $path) !== 1) {
            return null;
        }

        return $path;
    }

    private static function composerRequires(string $workspace, string $package): bool
    {
        $composer = self::readJson($workspace.'/composer.json');

        return isset($composer['require'][$package]) || isset($composer['require-dev'][$package]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
