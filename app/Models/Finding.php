<?php

namespace App\Models;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\Reachability;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Finding extends Model
{
    use HasUuids;

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
            'reachability' => Reachability::class,
            'noise_filtered' => 'boolean',
            'evidence' => 'array',
        ];
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
