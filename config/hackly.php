<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authorization & Safety
    |--------------------------------------------------------------------------
    */

    'allowlist_only' => (bool) env('HACKLY_ALLOWLIST_ONLY', false),

    'allowlist' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('HACKLY_ALLOWLIST', ''))
    ))),

    /*
    | Block private / loopback targets in production unless explicitly enabled.
    */
    'allow_private_targets' => (bool) env('HACKLY_ALLOW_PRIVATE_TARGETS', env('APP_ENV') !== 'production'),

    /*
    |--------------------------------------------------------------------------
    | Binary Paths
    |--------------------------------------------------------------------------
    */

    'binaries' => [
        'nmap' => env('HACKLY_NMAP', 'nmap'),
        'dig' => env('HACKLY_DIG', 'dig'),
        'whois' => env('HACKLY_WHOIS', 'whois'),
        'nuclei' => env('HACKLY_NUCLEI', 'nuclei'),
        'zap' => env(
            'HACKLY_ZAP',
            is_executable('/Applications/ZAP.app/Contents/Java/zap.sh')
                ? '/Applications/ZAP.app/Contents/Java/zap.sh'
                : 'zap.sh'
        ),
        'git' => env('HACKLY_GIT', 'git'),
        'composer' => env('HACKLY_COMPOSER', 'composer'),
        'semgrep' => env('HACKLY_SEMGREP', 'semgrep'),
        'trivy' => env('HACKLY_TRIVY', 'trivy'),
        'gitleaks' => env('HACKLY_GITLEAKS', 'gitleaks'),
        'checkov' => env('HACKLY_CHECKOV', 'checkov'),
    ],

    /*
    | Extra directories searched when resolving bare binary names.
    | PHP CLI / queue workers often lack interactive PATH entries like ~/.local/bin (pipx).
    */
    'binary_search_dirs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('HACKLY_BINARY_SEARCH_DIRS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Rate Limits (anti-ban)
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'per_target_per_minute' => (int) env('HACKLY_PER_TARGET_PER_MINUTE', 2),
        'global_concurrent' => (int) env('HACKLY_GLOBAL_CONCURRENT', 5),
        'jitter_seconds' => (int) env('HACKLY_JITTER_SECONDS', 5),
        'nmap_delay_ms' => (int) env('HACKLY_NMAP_DELAY_MS', 100),
        'task_spacing_seconds' => (int) env('HACKLY_TASK_SPACING_SECONDS', 10),
        'deep_cooldown_hours' => (int) env('HACKLY_DEEP_COOLDOWN_HOURS', 24),
    ],

    'quiet_hours' => [
        'enabled' => (bool) env('HACKLY_QUIET_HOURS_ENABLED', false),
        'start' => (int) env('HACKLY_QUIET_HOURS_START', 0),
        'end' => (int) env('HACKLY_QUIET_HOURS_END', 6),
        'timezone' => env('HACKLY_QUIET_HOURS_TZ', env('APP_TIMEZONE', 'UTC')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scan Profiles → Task Types
    |--------------------------------------------------------------------------
    */

    'profiles' => [
        'quick' => [
            'dns_info',
            'mail_security',
            'tls_check',
            'port_scan',
            'tech_fingerprint',
        ],
        'standard' => [
            'dns_info',
            'mail_security',
            'tls_check',
            'port_scan',
            'subdomain_enum',
            'origin_exposure',
            'tech_fingerprint',
            'path_discovery',
            'nuclei_owasp',
        ],
        'deep' => [
            'dns_info',
            'mail_security',
            'tls_check',
            'port_scan',
            'subdomain_enum',
            'origin_exposure',
            'tech_fingerprint',
            'path_discovery',
            'nuclei_owasp',
            'zap_baseline',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Mapping
    |--------------------------------------------------------------------------
    */

    'queues' => [
        'dns_info' => 'default',
        'mail_security' => 'default',
        'tls_check' => 'default',
        'subdomain_enum' => 'default',
        'origin_exposure' => 'default',
        'port_scan' => 'default',
        'tech_fingerprint' => 'default',
        'path_discovery' => 'default',
        'nuclei_owasp' => 'default',
        'zap_baseline' => 'default',
    ],

    /*
    |--------------------------------------------------------------------------
    | Scanner Defaults
    |--------------------------------------------------------------------------
    */

    'nmap' => [
        'top_ports' => (int) env('HACKLY_NMAP_TOP_PORTS', 50),
        // T3 + light -sV finishes reliably; T2 + 100 ports often exceeds process timeouts on filtered hosts.
        'timing' => env('HACKLY_NMAP_TIMING', 'T3'),
        'timeout' => (int) env('HACKLY_NMAP_TIMEOUT', 840),
    ],

    'subdomain' => [
        'wordlist' => storage_path('app/wordlists/subdomains-soft.txt'),
        'timeout' => (int) env('HACKLY_SUBDOMAIN_TIMEOUT', 180),
        'ct_logs_enabled' => (bool) env('HACKLY_CT_LOGS_ENABLED', true),
    ],

    'mail_security' => [
        'timeout' => (int) env('HACKLY_MAIL_SECURITY_TIMEOUT', 120),
        'dkim_selectors' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'HACKLY_DKIM_SELECTORS',
                // fm1/fm2/fm3 = Fastmail; include common ESP selectors to cut false negatives
                'default,google,selector1,selector2,k1,k2,s1,s2,dkim,mail,smtp,mx,fm1,fm2,fm3,mandrill,cm,s1024,s2048,everlytickey1,zendesk1,zendesk2,protonmail,pm,sig1,mailo,turbo'
            ))
        ))),
        'check_mta_sts_policy' => (bool) env('HACKLY_MAIL_CHECK_MTA_STS', true),
        'spf_max_dns_lookups' => (int) env('HACKLY_SPF_MAX_LOOKUPS', 10),
    ],

    'tls' => [
        'timeout' => (int) env('HACKLY_TLS_TIMEOUT', 90),
    ],

    'origin_exposure' => [
        'timeout' => (int) env('HACKLY_ORIGIN_EXPOSURE_TIMEOUT', 180),
    ],

    'nuclei' => [
        'rate_limit' => (int) env('HACKLY_NUCLEI_RATE_LIMIT', 30),
        'concurrency' => (int) env('HACKLY_NUCLEI_CONCURRENCY', 5),
        'timeout' => (int) env('HACKLY_NUCLEI_TIMEOUT', 600),
        // Writable HOME for queue workers (config/cache/templates). Avoids CWD permission errors.
        'home' => env('HACKLY_NUCLEI_HOME', storage_path('app/nuclei-home')),
        'templates_path' => env('HACKLY_NUCLEI_TEMPLATES'),
        'tags' => env('HACKLY_NUCLEI_TAGS', 'owasp,cve,misconfig,exposure,vuln,laravel,php,dotenv'),
    ],

    'zap' => [
        'timeout' => (int) env('HACKLY_ZAP_TIMEOUT', 900),
        'max_duration_minutes' => (int) env('HACKLY_ZAP_MAX_DURATION', 10),
        // Plugin IDs kept as informational / noise (never raise above Low).
        'informational_plugin_ids' => [
            '10027', // Suspicious Comments
            '10036', // Server Leaks Version Information
            '10109', // Modern Web Application
            '10104', // User Agent Fuzzer
            '10095', // Backup File Disclosure (often noisy)
            '120001', // Session Management Response Identified (automation)
            '111012', // Session Management Response Identified
        ],
        // Missing security headers are defense-in-depth — cap at Medium.
        'header_plugin_ids' => [
            '10035', // Strict-Transport-Security Header Not Set
            '10038', // Content Security Policy (CSP) Header Not Set
            '10021', // X-Content-Type-Options Header Missing
            '10020', // X-Frame-Options Header Not Set
            '10016', // Web Browser XSS Protection Not Enabled
            '10017', // Cross-Domain JavaScript Source File Inclusion
        ],
        // Drop known false positives (Laravel XSRF-TOKEN, Cloudflare /cdn-cgi/).
        'suppress_cookie_names' => ['XSRF-TOKEN'],
        'suppress_url_substrings' => ['/cdn-cgi/'],
    ],

    'tech_fingerprint' => [
        'timeout' => (int) env('HACKLY_TECH_FINGERPRINT_TIMEOUT', 90),
    ],

    'path_discovery' => [
        'wordlist' => storage_path('app/wordlists/paths-soft.txt'),
        'timeout' => (int) env('HACKLY_PATH_TIMEOUT', 300),
        'rate_limit' => (int) env('HACKLY_PATH_RATE_LIMIT', 20),
        'max_paths' => (int) env('HACKLY_PATH_MAX_PATHS', 100),
        'tags' => env('HACKLY_PATH_TAGS', 'discovery,exposure,config,laravel,php,dotenv'),
    ],

    'storage_path' => storage_path('app/scans'),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | job_timeout must exceed the longest scanner timeout (ZAP defaults to 900s).
    | DB_QUEUE_RETRY_AFTER / REDIS_QUEUE_RETRY_AFTER must be higher than this.
    |
    */

    'queue' => [
        'job_timeout' => (int) env('HACKLY_JOB_TIMEOUT', 960),
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub repository scanning (Aikido-style orchestration for Laravel/PHP)
    |--------------------------------------------------------------------------
    |
    | Tools: Semgrep (SAST), Trivy (SCA), Gitleaks (secrets), Checkov (IaC),
    | Composer audit + OSV, plus Hackly Laravel static/live pentests.
    | Post-processing: dedupe across tools, PHP reachability, noise filters.
    |
    */

    'repo' => [
        'workspace_path' => env('HACKLY_REPO_WORKSPACE', storage_path('app/repo-scans')),
        'storage_path' => env('HACKLY_REPO_OUTPUTS', storage_path('app/repo-scan-outputs')),
        'cleanup_workspace' => (bool) env('HACKLY_REPO_CLEANUP', true),
        'clone_timeout' => (int) env('HACKLY_REPO_CLONE_TIMEOUT', 300),
        'clone_depth' => (int) env('HACKLY_REPO_CLONE_DEPTH', 1),
        'job_timeout' => (int) env('HACKLY_REPO_JOB_TIMEOUT', 960),
        'nightly_at' => env('HACKLY_REPO_NIGHTLY_AT', '02:30'),
        'nightly_timezone' => env('HACKLY_REPO_NIGHTLY_TZ', env('APP_TIMEZONE', 'UTC')),
        // hackly:nightly uses these profiles (override via CLI flags).
        'nightly_repo_profile' => env('HACKLY_NIGHTLY_REPO_PROFILE', 'standard'),
        'nightly_target_profile' => env('HACKLY_NIGHTLY_TARGET_PROFILE', 'deep'),
        // hackly:hourly-repos — lighter profile; repo-only (no linked targets).
        'hourly_repo_profile' => env('HACKLY_HOURLY_REPO_PROFILE', 'quick'),

        'profiles' => [
            'quick' => [
                'composer_osv',
                'gitleaks_secrets',
                'laravel_php_audit',
            ],
            'standard' => [
                'semgrep_sast',
                'trivy_sca',
                'gitleaks_secrets',
                'composer_osv',
                'laravel_php_audit',
                'laravel_live_pentest',
            ],
            'deep' => [
                'semgrep_sast',
                'trivy_sca',
                'gitleaks_secrets',
                'checkov_iac',
                'composer_osv',
                'laravel_php_audit',
                'laravel_live_pentest',
            ],
        ],

        'queues' => [
            'semgrep_sast' => 'default',
            'trivy_sca' => 'default',
            'gitleaks_secrets' => 'default',
            'checkov_iac' => 'default',
            'composer_osv' => 'default',
            'laravel_php_audit' => 'default',
            'laravel_live_pentest' => 'default',
        ],

        'semgrep' => [
            'timeout' => (int) env('HACKLY_SEMGREP_TIMEOUT', 600),
            // PHP-focused rulesets; add comma-separated configs if needed.
            'config' => env('HACKLY_SEMGREP_CONFIG', 'p/php'),
        ],

        'trivy' => [
            'timeout' => (int) env('HACKLY_TRIVY_TIMEOUT', 600),
        ],

        'gitleaks' => [
            'timeout' => (int) env('HACKLY_GITLEAKS_TIMEOUT', 300),
        ],

        'checkov' => [
            'timeout' => (int) env('HACKLY_CHECKOV_TIMEOUT', 600),
        ],

        'composer' => [
            'timeout' => (int) env('HACKLY_COMPOSER_AUDIT_TIMEOUT', 300),
            'osv_enabled' => (bool) env('HACKLY_OSV_ENABLED', true),
        ],

        'laravel_audit' => [
            'timeout' => (int) env('HACKLY_LARAVEL_AUDIT_TIMEOUT', 120),
        ],

        'laravel_live' => [
            'timeout' => (int) env('HACKLY_LARAVEL_LIVE_TIMEOUT', 180),
            'request_timeout' => (int) env('HACKLY_LARAVEL_LIVE_REQUEST_TIMEOUT', 10),
        ],

        'noise' => [
            // When true, noisy findings are dropped instead of kept with noise_filtered=true.
            'drop_filtered' => (bool) env('HACKLY_REPO_DROP_NOISE', false),
            'secret_placeholders' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env(
                    'HACKLY_REPO_SECRET_PLACEHOLDERS',
                    'changeme,change-me,your-api-key,your_api_key,xxx,todo,placeholder,example,dummy,test1234,password,secret'
                ))
            ))),
            'suppress_rule_ids' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('HACKLY_REPO_SUPPRESS_RULES', ''))
            ))),
        ],

        'reachability' => [
            // When true, unreachable dependency findings are omitted from the final set.
            'hide_unreachable' => (bool) env('HACKLY_REPO_HIDE_UNREACHABLE', false),
        ],
    ],

];
