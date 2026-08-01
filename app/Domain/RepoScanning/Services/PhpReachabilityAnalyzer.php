<?php

namespace App\Domain\RepoScanning\Services;

use App\Domain\RepoScanning\DTO\RawFinding;
use App\Enums\Reachability;
use Illuminate\Support\Facades\File;

class PhpReachabilityAnalyzer
{
    /**
     * @return array{
     *   is_laravel: bool,
     *   dev_packages: list<string>,
     *   prod_packages: list<string>,
     *   namespaces: array<string, list<string>>,
     * }
     */
    public function buildContext(string $workspace): array
    {
        $composerJson = $this->readJson($workspace.'/composer.json');
        $composerLock = $this->readJson($workspace.'/composer.lock');

        $isLaravel = isset($composerJson['require']['laravel/framework'])
            || is_file($workspace.'/artisan')
            || is_dir($workspace.'/app/Http');

        $prodPackages = array_keys($composerJson['require'] ?? []);
        $devPackages = array_keys($composerJson['require-dev'] ?? []);

        $namespaces = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($composerLock[$section] ?? [] as $package) {
                if (! is_array($package) || ! isset($package['name'])) {
                    continue;
                }

                $name = (string) $package['name'];
                $psr4 = $package['autoload']['psr-4'] ?? [];
                $prefixes = [];

                if (is_array($psr4)) {
                    foreach (array_keys($psr4) as $prefix) {
                        $prefix = trim((string) $prefix, '\\');
                        if ($prefix !== '') {
                            $prefixes[] = $prefix;
                        }
                    }
                }

                $namespaces[$name] = $prefixes;
            }
        }

        return [
            'is_laravel' => $isLaravel,
            'dev_packages' => array_values(array_map('strtolower', $devPackages)),
            'prod_packages' => array_values(array_map('strtolower', $prodPackages)),
            'namespaces' => $namespaces,
        ];
    }

    /**
     * @param  list<RawFinding>  $findings
     * @param  array{is_laravel?: bool, dev_packages?: list<string>, prod_packages?: list<string>, namespaces?: array<string, list<string>>}  $context
     * @return list<array{finding: RawFinding, reachability: Reachability, confidence: int, reasons: list<string>}>
     */
    public function analyze(string $workspace, array $findings, array $context): array
    {
        $appRoots = $this->searchRoots($workspace);
        $results = [];

        foreach ($findings as $finding) {
            $results[] = $this->analyzeOne($workspace, $finding, $context, $appRoots);
        }

        return $results;
    }

    /**
     * @param  array{is_laravel?: bool, dev_packages?: list<string>, prod_packages?: list<string>, namespaces?: array<string, list<string>>}  $context
     * @param  list<string>  $appRoots
     * @return array{finding: RawFinding, reachability: Reachability, confidence: int, reasons: list<string>}
     */
    private function analyzeOne(string $workspace, RawFinding $finding, array $context, array $appRoots): array
    {
        $reasons = [];
        $package = strtolower((string) $finding->package);
        $devPackages = $context['dev_packages'] ?? [];
        $file = $finding->file;

        // File-level SAST / secrets / IaC: treat as reachable if under app code.
        if (filled($file)) {
            $normalized = ltrim(str_replace('\\', '/', $file), '/');

            if (preg_match('#^(app|routes|config|bootstrap|database|resources/views)/#', $normalized)) {
                return [
                    'finding' => $finding,
                    'reachability' => Reachability::Reachable,
                    'confidence' => 85,
                    'reasons' => ['path_in_application_code'],
                ];
            }

            if (preg_match('#^(vendor|node_modules|storage/framework|tests?)/#', $normalized)) {
                return [
                    'finding' => $finding,
                    'reachability' => Reachability::Unreachable,
                    'confidence' => 70,
                    'reasons' => ['path_outside_runtime_app'],
                ];
            }
        }

        if ($package === '') {
            return [
                'finding' => $finding,
                'reachability' => Reachability::Unknown,
                'confidence' => 40,
                'reasons' => ['no_package_context'],
            ];
        }

        if (in_array($package, $devPackages, true)) {
            return [
                'finding' => $finding,
                'reachability' => Reachability::Unreachable,
                'confidence' => 80,
                'reasons' => ['require_dev_only'],
            ];
        }

        $prefixes = $context['namespaces'][$package] ?? $context['namespaces'][$finding->package ?? ''] ?? [];

        if ($prefixes === []) {
            // Package present in prod require without discoverable PSR-4 → unknown but elevated.
            if (in_array($package, $context['prod_packages'] ?? [], true)) {
                return [
                    'finding' => $finding,
                    'reachability' => Reachability::Unknown,
                    'confidence' => 55,
                    'reasons' => ['prod_dependency_no_namespace_map'],
                ];
            }

            return [
                'finding' => $finding,
                'reachability' => Reachability::Unknown,
                'confidence' => 35,
                'reasons' => ['package_namespace_unknown'],
            ];
        }

        $hits = $this->countNamespaceUsages($appRoots, $prefixes);

        if ($hits > 0) {
            $reasons[] = "namespace_used_in_app:{$hits}";

            return [
                'finding' => $finding,
                'reachability' => Reachability::Reachable,
                'confidence' => min(95, 60 + min($hits, 10) * 3),
                'reasons' => $reasons,
            ];
        }

        // Laravel package auto-discovery may load service providers without direct imports.
        if (($context['is_laravel'] ?? false) && $this->hasLaravelProvider($workspace, $package)) {
            return [
                'finding' => $finding,
                'reachability' => Reachability::Reachable,
                'confidence' => 65,
                'reasons' => ['laravel_package_auto_discovery'],
            ];
        }

        return [
            'finding' => $finding,
            'reachability' => Reachability::Unreachable,
            'confidence' => 70,
            'reasons' => ['no_app_references_to_package_namespaces'],
        ];
    }

    /**
     * @return list<string>
     */
    private function searchRoots(string $workspace): array
    {
        $roots = [];

        foreach (['app', 'routes', 'config', 'bootstrap', 'database'] as $dir) {
            $path = $workspace.'/'.$dir;
            if (is_dir($path)) {
                $roots[] = $path;
            }
        }

        return $roots;
    }

    /**
     * @param  list<string>  $roots
     * @param  list<string>  $prefixes
     */
    private function countNamespaceUsages(array $roots, array $prefixes): int
    {
        if ($roots === [] || $prefixes === []) {
            return 0;
        }

        $hits = 0;
        $patterns = array_map(function (string $prefix) {
            $prefix = trim($prefix, '\\');

            return preg_quote($prefix, '/');
        }, $prefixes);

        $regex = '/('.implode('|', $patterns).')\\\\/i';

        foreach ($roots as $root) {
            foreach (File::allFiles($root) as $file) {
                if (! in_array(strtolower($file->getExtension()), ['php', 'blade.php'], true)
                    && ! str_ends_with($file->getFilename(), '.blade.php')) {
                    if ($file->getExtension() !== 'php') {
                        continue;
                    }
                }

                $contents = @file_get_contents($file->getPathname());
                if (! is_string($contents) || $contents === '') {
                    continue;
                }

                if (preg_match($regex, $contents)) {
                    $hits++;
                }

                if ($hits >= 20) {
                    return $hits;
                }
            }
        }

        return $hits;
    }

    private function hasLaravelProvider(string $workspace, string $package): bool
    {
        $installed = $this->readJson($workspace.'/vendor/composer/installed.json');
        $packages = $installed['packages'] ?? $installed;

        if (! is_array($packages)) {
            return false;
        }

        foreach ($packages as $pkg) {
            if (! is_array($pkg) || strtolower((string) ($pkg['name'] ?? '')) !== $package) {
                continue;
            }

            $providers = $pkg['extra']['laravel']['providers'] ?? [];

            return is_array($providers) && $providers !== [];
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
