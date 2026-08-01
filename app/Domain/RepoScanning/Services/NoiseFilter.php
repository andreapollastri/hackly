<?php

namespace App\Domain\RepoScanning\Services;

use App\Domain\RepoScanning\DTO\RawFinding;
use App\Enums\FindingSeverity;

class NoiseFilter
{
    /**
     * Annotate / drop noisy findings. Returns kept findings with evidence flags.
     *
     * @param  list<RawFinding>  $findings
     * @param  array{dev_packages?: list<string>, is_laravel?: bool}  $context
     * @return array{kept: list<RawFinding>, filtered: list<RawFinding>}
     */
    public function filter(array $findings, array $context = []): array
    {
        $devPackages = array_map('strtolower', $context['dev_packages'] ?? []);
        $kept = [];
        $filtered = [];

        foreach ($findings as $finding) {
            $reasons = $this->noiseReasons($finding, $devPackages);

            if ($reasons === []) {
                $kept[] = $finding;

                continue;
            }

            $drop = (bool) config('hackly.repo.noise.drop_filtered', false);

            $annotated = new RawFinding(
                title: $finding->title,
                severity: $this->maybeDowngrade($finding, $reasons),
                source: $finding->source,
                category: $finding->category,
                cve: $finding->cve,
                description: $finding->description,
                evidence: array_merge($finding->evidence, [
                    'noise_reasons' => $reasons,
                    'noise_filtered' => true,
                ]),
                package: $finding->package,
                packageVersion: $finding->packageVersion,
                file: $finding->file,
                line: $finding->line,
                ruleId: $finding->ruleId,
                tools: $finding->tools,
                dedupeKey: $finding->resolvedDedupeKey(),
            );

            if ($drop) {
                $filtered[] = $annotated;
            } else {
                $kept[] = $annotated;
                $filtered[] = $annotated;
            }
        }

        return ['kept' => $kept, 'filtered' => $filtered];
    }

    /**
     * @param  list<string>  $devPackages
     * @return list<string>
     */
    private function noiseReasons(RawFinding $finding, array $devPackages): array
    {
        $reasons = [];
        $package = strtolower((string) $finding->package);
        $file = (string) ($finding->file ?? '');
        $title = strtolower($finding->title);
        $preview = strtolower((string) ($finding->evidence['secret'] ?? $finding->evidence['preview'] ?? ''));

        if ($package !== '' && in_array($package, $devPackages, true)) {
            $reasons[] = 'dev_dependency_only';
        }

        if (preg_match('#(^|/)(tests?|vendor|node_modules|storage/framework)/#i', $file)) {
            $reasons[] = 'path_out_of_app_scope';
        }

        foreach ((array) config('hackly.repo.noise.secret_placeholders', []) as $placeholder) {
            if ($placeholder !== '' && str_contains($preview, strtolower((string) $placeholder))) {
                $reasons[] = 'secret_placeholder';
                break;
            }
        }

        foreach ((array) config('hackly.repo.noise.suppress_rule_ids', []) as $rule) {
            if ($finding->ruleId && strcasecmp((string) $finding->ruleId, (string) $rule) === 0) {
                $reasons[] = 'suppressed_rule';
                break;
            }
        }

        if (str_contains($title, 'xsrf-token') || str_contains($title, 'csrf-token cookie')) {
            $reasons[] = 'laravel_expected_cookie';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  list<string>  $reasons
     */
    private function maybeDowngrade(RawFinding $finding, array $reasons): FindingSeverity
    {
        if (in_array('dev_dependency_only', $reasons, true) || in_array('secret_placeholder', $reasons, true)) {
            return FindingSeverity::Low;
        }

        return $finding->severity;
    }
}
