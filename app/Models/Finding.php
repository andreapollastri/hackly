<?php

namespace App\Models;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\Reachability;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Finding extends Model
{
    use HasUuids;

    /**
     * Categories that record context rather than a problem (passed checks, scan deltas).
     */
    public const NON_ISSUE_CATEGORIES = ['passed', 'scan_diff'];

    public const FALSE_POSITIVE_NOTE_MIN = 8;

    public const FALSE_POSITIVE_NOTE_MAX = 2000;

    protected $fillable = [
        'asset_id',
        'repository_id',
        'scan_id',
        'repo_scan_id',
        'scan_task_id',
        'repo_scan_task_id',
        'severity',
        'title',
        'category',
        'cve',
        'source',
        'status',
        'false_positive_note',
        'false_positive_noted_at',
        'false_positive_noted_by',
        'reachability',
        'noise_filtered',
        'confidence',
        'fingerprint',
        'evidence',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'severity' => FindingSeverity::class,
            'status' => FindingStatus::class,
            'false_positive_noted_at' => 'datetime',
            'published_at' => 'datetime',
            'reachability' => Reachability::class,
            'noise_filtered' => 'boolean',
            'evidence' => 'array',
        ];
    }

    /**
     * Only real issues — excludes passed checks and scan delta bookkeeping rows.
     */
    #[Scope]
    protected function issues(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereNull('category')
            ->orWhereNotIn('category', self::NON_ISSUE_CATEGORIES));
    }

    #[Scope]
    protected function open(Builder $query): void
    {
        $query->where('status', FindingStatus::Open);
    }

    #[Scope]
    protected function unresolved(Builder $query): void
    {
        $query->whereIn('status', [FindingStatus::Open->value, FindingStatus::Ack->value]);
    }

    /**
     * Status to store when a scanner reports this fingerprint again.
     * Triage decisions (acknowledged / false positive) survive re-scans; anything else (re)opens.
     */
    public static function statusAfterRedetection(string $fingerprint): FindingStatus
    {
        $current = static::query()->where('fingerprint', $fingerprint)->value('status');

        $current = $current instanceof FindingStatus ? $current : FindingStatus::tryFrom((string) $current);

        return in_array($current, [FindingStatus::Ack, FindingStatus::FalsePositive], true)
            ? $current
            : FindingStatus::Open;
    }

    /**
     * A false positive keeps the note that justified it. Other statuses leave that note in place.
     */
    public function applyTriage(FindingStatus $status, ?string $note = null, ?string $userId = null): void
    {
        $attributes = ['status' => $status];

        if ($status === FindingStatus::FalsePositive) {
            $note = trim((string) $note);
            $length = mb_strlen($note);

            if ($length < self::FALSE_POSITIVE_NOTE_MIN || $length > self::FALSE_POSITIVE_NOTE_MAX) {
                throw new InvalidArgumentException(
                    'A note between '.self::FALSE_POSITIVE_NOTE_MIN.' and '.self::FALSE_POSITIVE_NOTE_MAX.' characters is required when marking a finding as a false positive.',
                );
            }

            $attributes['false_positive_note'] = $note;
            $attributes['false_positive_noted_at'] = now();
            $attributes['false_positive_noted_by'] = $userId;
        }

        $this->update($attributes);
    }

    public function isPublished(): bool
    {
        return filled($this->publish_hash);
    }

    public function publish(?string $userId = null): void
    {
        if ($this->isPublished()) {
            return;
        }

        $this->forceFill([
            'publish_hash' => Str::random(40),
            'published_at' => now(),
            'published_by' => $userId,
        ])->save();
    }

    public function unpublish(): void
    {
        $this->forceFill([
            'publish_hash' => null,
            'published_at' => null,
            'published_by' => null,
        ])->save();
    }

    public function publicUrl(): ?string
    {
        if (! $this->isPublished()) {
            return null;
        }

        return route('findings.public', ['hash' => $this->publish_hash]);
    }

    public function falsePositiveAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'false_positive_noted_by');
    }

    public static function categoryLabel(?string $category): string
    {
        if (blank($category)) {
            return '—';
        }

        $acronyms = [
            'sca' => 'SCA', 'sast' => 'SAST', 'iac' => 'IaC', 'spf' => 'SPF', 'dkim' => 'DKIM',
            'dmarc' => 'DMARC', 'mx' => 'MX', 'mta_sts' => 'MTA-STS', 'tlsrpt' => 'TLS-RPT',
            'bimi' => 'BIMI', 'cors' => 'CORS', 'caa' => 'CAA', 'dnssec' => 'DNSSEC', 'tls' => 'TLS',
            'ip' => 'IP', 'zap' => 'ZAP', 'whois' => 'WHOIS', 'scan_diff' => 'Scan delta',
            'dependency_health' => 'Dependency health',
        ];

        return $acronyms[$category] ?? str($category)->replace('_', ' ')->ucfirst()->toString();
    }

    public function isIssue(): bool
    {
        return ! in_array($this->category, self::NON_ISSUE_CATEGORIES, true);
    }

    public function isRepositoryFinding(): bool
    {
        return $this->repository_id !== null && $this->asset_id === null;
    }

    public function subjectName(): string
    {
        return $this->asset?->value
            ?? $this->repository?->full_name
            ?? '—';
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function repoScan(): BelongsTo
    {
        return $this->belongsTo(RepoScan::class);
    }

    public function scanTask(): BelongsTo
    {
        return $this->belongsTo(ScanTask::class);
    }

    public function repoScanTask(): BelongsTo
    {
        return $this->belongsTo(RepoScanTask::class);
    }

    /**
     * Human-readable evidence lines for reports and detail views.
     *
     * @return list<string>
     */
    public function evidenceDetailLines(): array
    {
        $evidence = is_array($this->evidence) ? $this->evidence : [];
        $lines = [];

        $appendList = function (string $label, mixed $value) use (&$lines): void {
            if (! is_array($value) || $value === []) {
                return;
            }

            $scalar = array_values(array_filter($value, fn ($item) => is_scalar($item) && filled($item)));

            if ($scalar !== []) {
                $lines[] = $label.': '.implode(', ', $scalar);
            }
        };

        if (filled($evidence['host'] ?? null)) {
            $lines[] = 'Host: '.$evidence['host'];
        }

        $appendList('IP', $evidence['ips'] ?? null);
        $appendList('A', $evidence['a'] ?? null);
        $appendList('AAAA', $evidence['aaaa'] ?? null);
        $appendList('CNAME', $evidence['cnames'] ?? null);
        $appendList('NS', $evidence['ns'] ?? null);

        if (filled($evidence['url'] ?? null)) {
            $lines[] = 'URL: '.$evidence['url'];
        }

        if (array_key_exists('status', $evidence) && $evidence['status'] !== null && $evidence['status'] !== '') {
            $lines[] = 'HTTP status: '.$evidence['status'];
        }

        foreach ([
            'matched_at' => 'Matched at',
            'template' => 'Template',
            'type' => 'Type',
            'plugin_id' => 'Plugin',
            'confidence' => 'Confidence',
            'solution' => 'Solution',
            'port' => 'Port',
            'service' => 'Service',
            'product' => 'Product',
            'version' => 'Version',
            'preview' => 'Preview',
            'file' => 'File',
            'line' => 'Line',
            'package' => 'Package',
            'package_version' => 'Package version',
            'rule_id' => 'Rule',
            'repository' => 'Repository',
            'commit_sha' => 'Commit',
            'dedupe_key' => 'Dedupe key',
        ] as $key => $label) {
            if (filled($evidence[$key] ?? null) && is_scalar($evidence[$key])) {
                $lines[] = $label.': '.$evidence[$key];
            }
        }

        if (! empty($evidence['records']) && is_array($evidence['records'])) {
            $lines[] = 'Records:';

            foreach (array_slice($evidence['records'], 0, 40) as $record) {
                $lines[] = '  - '.(is_scalar($record)
                    ? (string) $record
                    : (string) json_encode($record, JSON_UNESCAPED_SLASHES));
            }
        }

        if (! empty($evidence['note']) && $lines === []) {
            $lines[] = (string) $evidence['note'];
        }

        $appendList('Tools', $evidence['tools'] ?? null);
        $appendList('Noise reasons', $evidence['noise_reasons'] ?? null);
        $appendList('Reachability', $evidence['reachability_reasons'] ?? null);

        $known = [
            'host', 'ips', 'a', 'aaaa', 'cnames', 'ns', 'url', 'status', 'matched_at',
            'template', 'type', 'plugin_id', 'confidence', 'solution', 'port', 'service',
            'product', 'version', 'records', 'preview', 'note', 'curl_command',
            'file', 'line', 'package', 'package_version', 'rule_id', 'repository',
            'commit_sha', 'dedupe_key', 'tools', 'noise_reasons', 'reachability_reasons',
            'noise_filtered', 'merged_sources', 'fixed_version', 'target', 'is_laravel',
        ];

        foreach ($evidence as $key => $value) {
            if (in_array($key, $known, true) || $value === null || $value === '' || $value === []) {
                continue;
            }

            $label = ucfirst(str_replace('_', ' ', (string) $key));

            if (is_scalar($value)) {
                $lines[] = $label.': '.$value;
            } elseif (is_array($value) && array_is_list($value) && collect($value)->every(fn ($item) => is_scalar($item))) {
                $lines[] = $label.': '.implode(', ', $value);
            }
        }

        return $lines;
    }
}
