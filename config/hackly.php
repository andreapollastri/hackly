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
    ],

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
            'port_scan',
            'tech_fingerprint',
        ],
        'standard' => [
            'dns_info',
            'port_scan',
            'subdomain_enum',
            'tech_fingerprint',
            'path_discovery',
            'nuclei_owasp',
        ],
        'deep' => [
            'dns_info',
            'port_scan',
            'subdomain_enum',
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
        'subdomain_enum' => 'default',
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
    ],

    'tech_fingerprint' => [
        'timeout' => (int) env('HACKLY_TECH_FINGERPRINT_TIMEOUT', 60),
    ],

    'path_discovery' => [
        'wordlist' => storage_path('app/wordlists/paths-soft.txt'),
        'timeout' => (int) env('HACKLY_PATH_TIMEOUT', 300),
        'rate_limit' => (int) env('HACKLY_PATH_RATE_LIMIT', 20),
        'max_paths' => (int) env('HACKLY_PATH_MAX_PATHS', 80),
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

];
