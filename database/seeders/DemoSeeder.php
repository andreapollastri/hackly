<?php

namespace Database\Seeders;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\Reachability;
use App\Enums\RepoScanTaskType;
use App\Enums\ScanProfile;
use App\Enums\ScanStatus;
use App\Enums\ScanTaskStatus;
use App\Enums\ScanTaskType;
use App\Models\Asset;
use App\Models\Finding;
use App\Models\GithubCredential;
use App\Models\RepoScan;
use App\Models\RepoScanTask;
use App\Models\Repository;
use App\Models\Scan;
use App\Models\ScanTask;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Populates a realistic, fully fictional workspace so you can explore Hackly
 * without running real scanners:  php artisan db:seed --class=DemoSeeder
 *
 * Nothing here touches the network — scans, tasks and findings are written directly.
 */
class DemoSeeder extends Seeder
{
    private User $user;

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->user = User::query()->updateOrCreate(
                ['email' => 'admin@hackly.test'],
                ['name' => 'Hackly Admin', 'password' => Hash::make('password')],
            );

            $credential = GithubCredential::query()->updateOrCreate(
                ['name' => 'Acme security bot'],
                [
                    'token' => 'github_pat_demo_'.Str::random(40),
                    'token_hint' => 'gith…demo',
                    'validation_status' => 'valid',
                    'last_validated_at' => now()->subDays(2),
                    'meta' => ['login' => 'acme-security-bot', 'scopes' => []],
                    'created_by' => $this->user->id,
                ],
            );

            $targets = $this->seedTargets();
            $repos = $this->seedRepositories($credential);

            $repos['acme/storefront']->assets()->syncWithoutDetaching([$targets['shop.acme.dev']->id]);
            $repos['acme/billing-api']->assets()->syncWithoutDetaching([$targets['acme.dev']->id, $targets['api.acme.dev']->id]);

            $this->seedTargetScans($targets);
            $this->seedRepoScans($repos);
        });
    }

    /**
     * @return array<string, Asset>
     */
    private function seedTargets(): array
    {
        $rows = [
            'acme.dev' => ['verified' => 41, 'status' => AssetStatus::Active],
            'shop.acme.dev' => ['verified' => 33, 'status' => AssetStatus::Active],
            'api.acme.dev' => ['verified' => 28, 'status' => AssetStatus::Active],
            'status.acme.io' => ['verified' => 12, 'status' => AssetStatus::Active],
            'legacy-portal.com' => ['verified' => 60, 'status' => AssetStatus::Paused],
            'staging.acme.dev' => ['verified' => null, 'status' => AssetStatus::Active],
        ];

        $assets = [];

        foreach ($rows as $domain => $row) {
            $asset = Asset::query()->firstOrNew(['value' => $domain]);
            $asset->fill([
                'type' => AssetType::Domain,
                'status' => $row['status'],
                'created_by' => $this->user->id,
                'verification_token' => 'hackly-verify='.Str::random(40),
            ]);
            $asset->verified_at = $row['verified'] !== null ? now()->subDays($row['verified']) : null;
            $asset->created_at = now()->subDays(($row['verified'] ?? 3) + 1);
            $asset->saveQuietly();

            $assets[$domain] = $asset;
        }

        return $assets;
    }

    /**
     * @return array<string, Repository>
     */
    private function seedRepositories(GithubCredential $credential): array
    {
        $rows = [
            'acme/storefront' => ['private' => true, 'language' => 'PHP', 'description' => 'Laravel 13 storefront & checkout'],
            'acme/billing-api' => ['private' => true, 'language' => 'PHP', 'description' => 'Subscriptions, invoices and Stripe webhooks'],
            'acme/marketing-site' => ['private' => false, 'language' => 'Blade', 'description' => 'Public marketing website'],
            'acme/infra' => ['private' => true, 'language' => 'HCL', 'description' => 'Terraform, Docker images and CI workflows'],
        ];

        $repos = [];

        foreach ($rows as $fullName => $row) {
            [$owner, $name] = explode('/', $fullName);

            $repos[$fullName] = Repository::query()->updateOrCreate(
                ['full_name' => $fullName],
                [
                    'github_credential_id' => $credential->id,
                    'owner' => $owner,
                    'name' => $name,
                    'default_branch' => 'main',
                    'is_private' => $row['private'],
                    'status' => 'active',
                    'html_url' => "https://github.com/{$fullName}",
                    'created_by' => $this->user->id,
                    'meta' => [
                        'description' => $row['description'],
                        'language' => $row['language'],
                        'topics' => ['laravel', 'acme'],
                    ],
                ],
            );
        }

        return $repos;
    }

    /**
     * @param  array<string, Asset>  $targets
     */
    private function seedTargetScans(array $targets): void
    {
        $plans = [
            ['acme.dev', ScanProfile::Deep, 26, ScanStatus::Completed],
            ['acme.dev', ScanProfile::Standard, 9, ScanStatus::Completed],
            ['acme.dev', ScanProfile::Standard, 1, ScanStatus::Completed],
            ['shop.acme.dev', ScanProfile::Standard, 19, ScanStatus::Completed],
            ['shop.acme.dev', ScanProfile::Deep, 4, ScanStatus::Completed],
            ['api.acme.dev', ScanProfile::Quick, 14, ScanStatus::Completed],
            ['api.acme.dev', ScanProfile::Standard, 0, ScanStatus::Running],
            ['status.acme.io', ScanProfile::Quick, 6, ScanStatus::Completed],
            ['legacy-portal.com', ScanProfile::Standard, 22, ScanStatus::Failed],
        ];

        foreach ($plans as [$domain, $profile, $daysAgo, $status]) {
            $asset = $targets[$domain];
            $startedAt = now()->subDays($daysAgo)->subHours(random_int(1, 6))->subMinutes(random_int(0, 59));

            if ($status === ScanStatus::Running) {
                $startedAt = now()->subMinutes(7);
            }

            $scan = new Scan([
                'asset_id' => $asset->id,
                'profile' => $profile,
                'status' => $status,
                'requested_by' => $this->user->id,
                'started_at' => $startedAt,
                'finished_at' => null,
                'error_message' => $status === ScanStatus::Failed ? 'All tasks failed: target refused connections (HTTP 403 from WAF).' : null,
            ]);
            $scan->created_at = $startedAt;
            $scan->updated_at = $startedAt;
            $scan->save();

            $cursor = $startedAt->copy();
            $types = config("hackly.profiles.{$profile->value}", []);

            foreach ($types as $index => $type) {
                $taskType = ScanTaskType::from($type);
                $duration = $this->taskDuration($taskType);
                $taskStatus = $this->targetTaskStatus($status, $index, count($types), $taskType);

                $task = new ScanTask([
                    'scan_id' => $scan->id,
                    'type' => $taskType,
                    'queue' => 'default',
                    'status' => $taskStatus,
                    'attempts' => in_array($taskStatus, [ScanTaskStatus::Pending, ScanTaskStatus::Queued], true) ? 0 : 1,
                    'sort_order' => $index,
                    'scheduled_at' => $cursor->copy(),
                    'started_at' => in_array($taskStatus, [ScanTaskStatus::Completed, ScanTaskStatus::Failed, ScanTaskStatus::Running], true) ? $cursor->copy() : null,
                    'finished_at' => in_array($taskStatus, [ScanTaskStatus::Completed, ScanTaskStatus::Failed, ScanTaskStatus::Skipped], true) ? $cursor->copy()->addSeconds($duration) : null,
                    'error_message' => match ($taskStatus) {
                        ScanTaskStatus::Failed => 'Connection refused by remote host (HTTP 403 — blocked by WAF).',
                        ScanTaskStatus::Skipped => 'Binary zap.sh not found — install OWASP ZAP or set HACKLY_ZAP.',
                        default => null,
                    },
                ]);
                $task->created_at = $startedAt;
                $task->save();

                if ($taskStatus === ScanTaskStatus::Completed) {
                    $count = $this->targetFindingsFor($asset, $scan, $task);
                    $task->forceFill(['meta' => ['exit_code' => 0, 'findings_count' => $count]])->saveQuietly();
                }

                $cursor->addSeconds($duration + 12);
            }

            if (in_array($status, [ScanStatus::Completed, ScanStatus::Failed], true)) {
                $scan->forceFill(['finished_at' => $cursor])->saveQuietly();
                $scan->snapshotFindingsSummary();
            }
        }
    }

    private function targetTaskStatus(ScanStatus $scanStatus, int $index, int $total, ScanTaskType $type): ScanTaskStatus
    {
        if ($scanStatus === ScanStatus::Failed) {
            return ScanTaskStatus::Failed;
        }

        if ($scanStatus === ScanStatus::Running) {
            return match (true) {
                $index < 4 => ScanTaskStatus::Completed,
                $index === 4 => ScanTaskStatus::Running,
                default => ScanTaskStatus::Queued,
            };
        }

        return $type === ScanTaskType::ZapBaseline && $total > 9 && random_int(0, 1) === 1
            ? ScanTaskStatus::Skipped
            : ScanTaskStatus::Completed;
    }

    private function taskDuration(ScanTaskType|RepoScanTaskType $type): int
    {
        return match ($type) {
            ScanTaskType::ZapBaseline => 612,
            ScanTaskType::NucleiOwasp => 274,
            ScanTaskType::SubdomainEnum => 96,
            ScanTaskType::PortScan => 81,
            ScanTaskType::PathDiscovery => 64,
            RepoScanTaskType::SemgrepSast => 143,
            RepoScanTaskType::TrivySca => 58,
            RepoScanTaskType::CheckovIac => 71,
            default => random_int(4, 22),
        };
    }

    private function targetFindingsFor(Asset $asset, Scan $scan, ScanTask $task): int
    {
        $catalog = [
            ScanTaskType::DnsInfo->value => [
                ['low', 'DNSSEC not enabled', 'dnssec', 'dns', 'Zone is not signed — responses can be spoofed by on-path attackers.', ['ns' => ['ns1.dnsimple.com', 'ns2.dnsimple.com']]],
                ['low', 'CAA record missing', 'caa', 'dns', 'Any public CA can issue certificates for this domain.', ['host' => $asset->value]],
                ['low', 'DNS records collected', 'passed', 'dns', 'A, AAAA, MX, NS and TXT records resolved.', ['a' => ['203.0.113.24'], 'aaaa' => ['2001:db8::24']]],
            ],
            ScanTaskType::MailSecurity->value => [
                ['medium', 'DMARC policy is p=none (monitor only)', 'dmarc', 'mail', 'Spoofed mail is delivered. Move to p=quarantine, then p=reject.', ['records' => ['v=DMARC1; p=none; rua=mailto:dmarc@'.$asset->value]]],
                ['low', 'DKIM key under 2048 bits (google)', 'dkim', 'mail', '1024-bit RSA keys are considered weak.', ['host' => 'google._domainkey.'.$asset->value]],
                ['low', 'SPF record found', 'passed', 'mail', 'SPF hard-fail (-all) is configured.', ['records' => ['v=spf1 include:_spf.google.com -all']]],
            ],
            ScanTaskType::TlsCheck->value => [
                ['medium', 'TLS certificate expires in 12 days', 'certificate', 'tls', 'Renew the certificate or check the ACME automation.', ['host' => $asset->value, 'port' => 443]],
                ['low', 'Legacy TLS protocols disabled', 'passed', 'tls', 'TLS 1.0 and 1.1 are rejected.', ['host' => $asset->value]],
            ],
            ScanTaskType::PortScan->value => [
                ['high', 'MySQL port exposed to the internet (3306/tcp)', 'open_port', 'nmap', 'Database ports should never be reachable from the public internet.', ['port' => 3306, 'service' => 'mysql', 'product' => 'MySQL', 'version' => '8.0.36']],
                ['low', 'SSH open (22/tcp)', 'open_port', 'nmap', 'Restrict SSH to a bastion or VPN where possible.', ['port' => 22, 'service' => 'ssh', 'product' => 'OpenSSH', 'version' => '9.6p1']],
            ],
            ScanTaskType::SubdomainEnum->value => [
                ['high', 'Possible subdomain takeover: old-blog.'.$asset->value.' (GitHub Pages)', 'subdomain_takeover', 'dns', 'CNAME points to an unclaimed GitHub Pages site.', ['host' => 'old-blog.'.$asset->value, 'cnames' => ['acme.github.io']]],
                ['low', 'Subdomain discovered: grafana.'.$asset->value, 'subdomain', 'dns', 'Found through Certificate Transparency logs.', ['host' => 'grafana.'.$asset->value, 'ips' => ['203.0.113.40']]],
            ],
            ScanTaskType::OriginExposure->value => [
                ['medium', 'Origin IP reachable bypassing edge: 198.51.100.7', 'origin_exposure', 'origin', 'The origin answers directly for the Host header, bypassing CDN/WAF protections.', ['ip' => '198.51.100.7', 'status' => 200]],
            ],
            ScanTaskType::TechFingerprint->value => [
                ['high', 'APP_DEBUG / Ignition error page exposed', 'laravel_debug', 'http', 'Stack traces and environment details leak to visitors.', ['url' => 'https://'.$asset->value.'/_ignition/health-check', 'status' => 200]],
                ['medium', 'CORS reflects arbitrary Origin', 'cors', 'http', 'Access-Control-Allow-Origin mirrors any Origin header.', ['url' => 'https://'.$asset->value.'/api/user', 'status' => 200]],
                ['low', 'Cookie missing SameSite: XSRF-TOKEN', 'cookie', 'http', 'Set SameSite=Lax or Strict on session cookies.', ['url' => 'https://'.$asset->value]],
                ['low', 'PHP version disclosed (PHP/8.2.12)', 'tech_fingerprint', 'http', 'X-Powered-By header reveals the runtime version.', ['url' => 'https://'.$asset->value]],
            ],
            ScanTaskType::PathDiscovery->value => [
                ['high', 'Path discovered: /.env (HTTP 200)', 'path_discovery', 'http', 'Environment file is publicly downloadable and may contain secrets.', ['url' => 'https://'.$asset->value.'/.env', 'status' => 200]],
                ['medium', 'Path discovered: /telescope (HTTP 200)', 'path_discovery', 'http', 'Laravel Telescope dashboard is reachable without authentication.', ['url' => 'https://'.$asset->value.'/telescope', 'status' => 200]],
            ],
            ScanTaskType::NucleiOwasp->value => [
                ['medium', 'Missing Content-Security-Policy header', 'headers', 'nuclei', 'No CSP is set; XSS impact is not mitigated.', ['template' => 'http-missing-security-headers', 'matched_at' => 'https://'.$asset->value]],
                ['low', 'Strict-Transport-Security max-age below 1 year', 'headers', 'nuclei', 'Increase HSTS max-age to at least 31536000.', ['template' => 'hsts-weak', 'matched_at' => 'https://'.$asset->value]],
            ],
            ScanTaskType::ZapBaseline->value => [
                ['medium', 'Anti-CSRF tokens missing on login form', 'zap', 'zap', 'ZAP passive rule 10202.', ['plugin_id' => '10202', 'url' => 'https://'.$asset->value.'/login']],
            ],
        ];

        $rows = $catalog[$task->type->value] ?? [];
        $count = 0;

        foreach ($rows as $i => [$severity, $title, $category, $source, $description, $evidence]) {
            // Deterministic subset per target so each one looks different.
            if (crc32($asset->value.$title) % 5 === 0 && $category !== 'passed') {
                continue;
            }

            $count++;

            $fingerprint = 'demo-'.sha1($asset->id.'|'.$title);
            $status = $this->pickStatus($fingerprint, $category);
            $created = $task->started_at->copy()->addSeconds($i);

            $finding = Finding::query()->firstOrNew(['fingerprint' => $fingerprint]);
            $isNew = ! $finding->exists;

            $finding->fill([
                'asset_id' => $asset->id,
                'scan_id' => $scan->id,
                'scan_task_id' => $task->id,
                'severity' => FindingSeverity::from($severity),
                'title' => $title,
                'category' => $category,
                'source' => $source,
                'status' => $status,
                'evidence' => $evidence,
                'description' => $description,
            ]);

            if ($isNew) {
                $finding->created_at = $created;
            }

            $finding->updated_at = $created;
            $finding->save();
        }

        return $count;
    }

    /**
     * @param  array<string, Repository>  $repos
     */
    private function seedRepoScans(array $repos): void
    {
        $plans = [
            ['acme/storefront', ScanProfile::Standard, 17, ScanStatus::Completed],
            ['acme/storefront', ScanProfile::Deep, 2, ScanStatus::Completed],
            ['acme/billing-api', ScanProfile::Standard, 11, ScanStatus::Completed],
            ['acme/billing-api', ScanProfile::Quick, 3, ScanStatus::Completed],
            ['acme/marketing-site', ScanProfile::Quick, 8, ScanStatus::Completed],
            ['acme/infra', ScanProfile::Deep, 0, ScanStatus::Running],
        ];

        $profileTasks = [
            'quick' => [RepoScanTaskType::GitleaksSecrets, RepoScanTaskType::ComposerOsv, RepoScanTaskType::LaravelPhpAudit],
            'standard' => [RepoScanTaskType::SemgrepSast, RepoScanTaskType::TrivySca, RepoScanTaskType::GitleaksSecrets, RepoScanTaskType::ComposerOsv, RepoScanTaskType::ComposerOutdated, RepoScanTaskType::LaravelPhpAudit],
            'deep' => [RepoScanTaskType::SemgrepSast, RepoScanTaskType::TrivySca, RepoScanTaskType::GitleaksSecrets, RepoScanTaskType::CheckovIac, RepoScanTaskType::ComposerOsv, RepoScanTaskType::ComposerOutdated, RepoScanTaskType::LaravelPhpAudit],
        ];

        foreach ($plans as [$fullName, $profile, $daysAgo, $status]) {
            $repo = $repos[$fullName];
            $startedAt = $status === ScanStatus::Running
                ? now()->subMinutes(3)
                : now()->subDays($daysAgo)->subHours(random_int(1, 8));
            $sha = substr(sha1($fullName.$daysAgo), 0, 40);

            $scan = new RepoScan([
                'repository_id' => $repo->id,
                'profile' => $profile,
                'status' => $status,
                'commit_sha' => $sha,
                'requested_by' => $this->user->id,
                'started_at' => $startedAt,
            ]);
            $scan->created_at = $startedAt;
            $scan->updated_at = $startedAt;
            $scan->save();

            $cursor = $startedAt->copy()->addSeconds(9);

            foreach ($profileTasks[$profile->value] as $index => $type) {
                $duration = $this->taskDuration($type);
                $taskStatus = match (true) {
                    $status === ScanStatus::Running && $index < 2 => ScanTaskStatus::Completed,
                    $status === ScanStatus::Running && $index === 2 => ScanTaskStatus::Running,
                    $status === ScanStatus::Running => ScanTaskStatus::Queued,
                    $type === RepoScanTaskType::CheckovIac && $fullName !== 'acme/infra' => ScanTaskStatus::Skipped,
                    default => ScanTaskStatus::Completed,
                };

                $task = new RepoScanTask([
                    'repo_scan_id' => $scan->id,
                    'type' => $type,
                    'queue' => 'default',
                    'status' => $taskStatus,
                    'attempts' => 1,
                    'sort_order' => $index,
                    'scheduled_at' => $cursor->copy(),
                    'started_at' => in_array($taskStatus, [ScanTaskStatus::Completed, ScanTaskStatus::Running], true) ? $cursor->copy() : null,
                    'finished_at' => in_array($taskStatus, [ScanTaskStatus::Completed, ScanTaskStatus::Skipped], true) ? $cursor->copy()->addSeconds($duration) : null,
                    'error_message' => $taskStatus === ScanTaskStatus::Skipped ? 'No Dockerfile, Terraform or workflow files found — nothing to check.' : null,
                ]);
                $task->created_at = $startedAt;
                $task->save();

                if ($taskStatus === ScanTaskStatus::Completed) {
                    $count = $this->repoFindingsFor($repo, $scan, $task);
                    $task->forceFill(['meta' => ['exit_code' => 0, 'findings_count' => $count]])->saveQuietly();
                }

                $cursor->addSeconds($duration + 4);
            }

            if ($status === ScanStatus::Completed) {
                $scan->forceFill(['finished_at' => $cursor])->saveQuietly();
                $scan->snapshotFindingsSummary();
                $repo->forceFill(['last_scanned_at' => $cursor, 'last_commit_sha' => $sha])->saveQuietly();
            }
        }
    }

    private function repoFindingsFor(Repository $repo, RepoScan $scan, RepoScanTask $task): int
    {
        $catalog = [
            RepoScanTaskType::SemgrepSast->value => [
                ['high', 'SQL injection via DB::raw() with request input', 'sast', 'semgrep', 'User input flows into a raw query in OrderController@search.', Reachability::Reachable, 90, ['file' => 'app/Http/Controllers/OrderController.php', 'line' => 87, 'rule_id' => 'php.laravel.security.laravel-sql-injection']],
                ['medium', 'Unescaped Blade output {!! !!} with user data', 'sast', 'semgrep', 'Reflected XSS risk in product reviews.', Reachability::Reachable, 75, ['file' => 'resources/views/products/show.blade.php', 'line' => 42, 'rule_id' => 'php.laravel.security.laravel-blade-unescaped']],
                ['low', 'Weak hash function md5() used', 'sast', 'semgrep', 'Only used for cache keys — verify it is not security sensitive.', Reachability::Unknown, 40, ['file' => 'app/Support/CacheKey.php', 'line' => 15, 'rule_id' => 'php.lang.security.weak-crypto']],
            ],
            RepoScanTaskType::TrivySca->value => [
                ['high', 'CVE-2025-27515 in laravel/framework (file validation bypass)', 'sca', 'trivy', 'Upgrade laravel/framework to the patched release.', Reachability::Reachable, 85, ['package' => 'laravel/framework', 'package_version' => '11.31.0', 'fixed_version' => '11.44.1']],
                ['medium', 'CVE-2024-45411 in twig/twig (sandbox bypass)', 'sca', 'trivy', 'Transitive dependency pulled by a dev tool.', Reachability::Unreachable, 50, ['package' => 'twig/twig', 'package_version' => '3.10.3']],
            ],
            RepoScanTaskType::GitleaksSecrets->value => [
                ['high', 'Stripe secret key committed', 'secret', 'gitleaks', 'A live Stripe key is present in the repository history. Rotate it immediately.', Reachability::Reachable, 95, ['file' => 'config/services.php', 'line' => 31, 'rule_id' => 'stripe-access-token']],
                ['low', 'Generic API key in test fixture', 'secret', 'gitleaks', 'Looks like a placeholder value.', Reachability::Unreachable, 20, ['file' => 'tests/Fixtures/webhook.json', 'line' => 4, 'rule_id' => 'generic-api-key', 'noise_reasons' => ['placeholder value', 'test fixture']]],
            ],
            RepoScanTaskType::CheckovIac->value => [
                ['medium', 'S3 bucket without server-side encryption', 'iac', 'checkov', 'CKV_AWS_19 — enable SSE-KMS on the bucket.', Reachability::Unknown, 70, ['file' => 'terraform/storage.tf', 'line' => 12, 'rule_id' => 'CKV_AWS_19']],
                ['low', 'Dockerfile runs as root', 'iac', 'checkov', 'CKV_DOCKER_3 — add a USER instruction.', Reachability::Unknown, 60, ['file' => 'docker/php/Dockerfile', 'line' => 1, 'rule_id' => 'CKV_DOCKER_3']],
            ],
            RepoScanTaskType::ComposerOsv->value => [
                ['medium', 'GHSA-3p32-j457-pg5x in guzzlehttp/guzzle', 'sca', 'composer-audit', 'Cookie leakage across domains on redirects.', Reachability::Reachable, 80, ['package' => 'guzzlehttp/guzzle', 'package_version' => '7.4.4']],
            ],
            RepoScanTaskType::ComposerOutdated->value => [
                ['medium', 'Laravel 11 is out of security support (latest 13)', 'dependency_health', 'composer-outdated', 'Laravel only ships security fixes for the latest two major versions. Upgrade the framework to keep receiving patches.', Reachability::Reachable, 90, ['package' => 'laravel/framework', 'package_version' => 'v11.31.0', 'latest' => 'v13.35.0', 'majors_behind' => 2]],
                ['medium', 'Abandoned package: fruitcake/laravel-cors', 'dependency_health', 'composer-outdated', 'fruitcake/laravel-cors is no longer maintained; its author recommends laravel/framework. Abandoned packages stop receiving security fixes.', Reachability::Reachable, 80, ['package' => 'fruitcake/laravel-cors', 'package_version' => 'v3.0.0', 'replacement' => 'laravel/framework']],
                ['low', '6 direct dependencies have compatible updates', 'dependency_health', 'composer-outdated', 'Updates within your version constraints. Running `composer update` regularly keeps bug and security fixes flowing.', Reachability::Unknown, null, ['records' => ['guzzlehttp/guzzle 7.8.1 → 7.10.0', 'livewire/livewire 3.5.12 → 3.6.4', 'spatie/laravel-backup 9.1.0 → 9.3.2', 'stripe/stripe-php 16.1.0 → 16.6.0', 'league/flysystem-aws-s3-v3 3.28.0 → 3.29.0', 'laravel/sanctum 4.0.2 → 4.0.8']]],
            ],
            RepoScanTaskType::LaravelPhpAudit->value => [
                ['high', 'Committed .env file', 'laravel', 'hackly-laravel-audit', '.env is tracked by git and contains APP_KEY and DB credentials.', Reachability::Reachable, 95, ['file' => '.env']],
                ['medium', 'Model without $fillable / $guarded (mass assignment)', 'laravel', 'hackly-laravel-audit', 'App\\Models\\Coupon is fully unguarded.', Reachability::Reachable, 70, ['file' => 'app/Models/Coupon.php', 'line' => 9]],
                ['low', 'Telescope enabled outside local environment', 'laravel', 'hackly-laravel-audit', 'Gate access to Telescope in production.', Reachability::Unknown, 55, ['file' => 'config/telescope.php']],
            ],
        ];

        $rows = $catalog[$task->type->value] ?? [];
        $count = 0;

        foreach ($rows as $i => [$severity, $title, $category, $source, $description, $reachability, $confidence, $evidence]) {
            if (crc32($repo->full_name.$title) % 3 === 0) {
                continue;
            }

            $count++;

            $fingerprint = 'demo-repo-'.sha1($repo->id.'|'.$title);
            $finding = Finding::query()->firstOrNew(['fingerprint' => $fingerprint]);
            $isNew = ! $finding->exists;
            $at = $task->started_at->copy()->addSeconds($i);

            $finding->fill([
                'repository_id' => $repo->id,
                'repo_scan_id' => $scan->id,
                'repo_scan_task_id' => $task->id,
                'severity' => FindingSeverity::from($severity),
                'title' => $title,
                'category' => $category,
                'source' => $source,
                'status' => $this->pickStatus($fingerprint, $category),
                'reachability' => $reachability,
                'noise_filtered' => isset($evidence['noise_reasons']),
                'confidence' => $confidence,
                'evidence' => $evidence + ['repository' => $repo->full_name, 'commit_sha' => $scan->commit_sha, 'tools' => [$source]],
                'description' => $description,
            ]);

            if ($isNew) {
                $finding->created_at = $at;
            }

            $finding->updated_at = $at;
            $finding->save();
        }

        return $count;
    }

    private function pickStatus(string $fingerprint, string $category): FindingStatus
    {
        if ($category === 'passed') {
            return FindingStatus::Open;
        }

        return match (crc32($fingerprint) % 9) {
            0 => FindingStatus::Fixed,
            1 => FindingStatus::Ack,
            2 => FindingStatus::FalsePositive,
            default => FindingStatus::Open,
        };
    }
}
