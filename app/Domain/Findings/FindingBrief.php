<?php

namespace App\Domain\Findings;

use App\Models\Finding;

/**
 * Full finding write-up, as Markdown for a person and as a prompt for an assistant.
 * The public share page uses the same text and omits who inside the workspace wrote the note.
 */
class FindingBrief
{
    public static function markdown(Finding $finding, bool $public = false): string
    {
        $finding->loadMissing(['asset:id,value', 'repository:id,full_name']);

        $lines = ['# '.self::singleLine($finding->title), ''];
        $lines[] = '**Severity:** '.($finding->severity?->value ?? '—');
        $lines[] = '**Status:** '.($finding->status?->value ?? '—');
        $lines[] = '**Subject:** '.self::singleLine($finding->subjectName());
        $lines[] = '**Source:** '.self::singleLine((string) $finding->source);
        $lines[] = '**Category:** '.Finding::categoryLabel($finding->category);

        if (filled($finding->cve)) {
            $lines[] = '**CVE:** '.self::singleLine((string) $finding->cve);
        }

        if ($finding->reachability) {
            $lines[] = '**Reachability:** '.$finding->reachability->value;
        }

        if ($finding->confidence !== null) {
            $lines[] = '**Confidence:** '.$finding->confidence.'%';
        }

        if ($finding->isRepositoryFinding()) {
            $lines[] = '**Noise filter:** '.($finding->noise_filtered ? 'likely noise' : 'kept as signal');
        }

        $lines[] = '**First seen:** '.($finding->created_at?->toIso8601String() ?? '—');
        $lines[] = '**Last updated:** '.($finding->updated_at?->toIso8601String() ?? '—');
        $lines[] = '**Fingerprint:** '.self::singleLine((string) $finding->fingerprint);

        if (! $public && $finding->isPublished()) {
            $lines[] = '**Public link:** '.$finding->publicUrl();
        }

        $lines[] = '';

        if (filled($finding->description)) {
            $lines[] = '## Description';
            $lines[] = '';
            $lines[] = trim((string) $finding->description);
            $lines[] = '';
        }

        if (filled($finding->false_positive_note)) {
            $lines[] = '## False positive note';
            $lines[] = '';
            $lines[] = trim((string) $finding->false_positive_note);
            $lines[] = '';

            if ($finding->false_positive_noted_at) {
                $lines[] = 'Noted '.$finding->false_positive_noted_at->toIso8601String().'.';
                $lines[] = '';
            }
        }

        $evidence = $finding->evidenceDetailLines();

        if ($evidence !== []) {
            $lines[] = '## Evidence';
            $lines[] = '';
            $lines[] = self::fence(implode("\n", $evidence), '');
            $lines[] = '';
        }

        $raw = is_array($finding->evidence) ? $finding->evidence : null;

        if ($raw !== null && $raw !== []) {
            $json = json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
            $lines[] = '## Raw evidence';
            $lines[] = '';
            $lines[] = self::fence($json, 'json');
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines))."\n";
    }

    public static function prompt(Finding $finding, bool $public = false): string
    {
        $brief = self::markdown($finding, $public);

        return <<<PROMPT
You are an application security engineer. Help me understand and fix the following finding from an authorized Hackly scan. The target or repository belongs to the person who ran the scan.

Explain the risk in this context, the likely cause, and a concrete fix. Prefer a small change over a rewrite.

---

{$brief}
---

## What I need from you

1. **What this means** — plain-language explanation and how serious it is here
2. **How to fix it** — the code, configuration, DNS, or dependency change to make
3. **How to verify** — what a follow-up check or the next Hackly scan should show
PROMPT;
    }

    private static function singleLine(string $value): string
    {
        $value = trim(str_replace(["\r\n", "\r", "\n"], ' ', $value));

        return $value === '' ? '—' : $value;
    }

    private static function fence(string $body, string $language): string
    {
        $marker = str_contains($body, '```') ? '~~~~' : '```';

        return $marker.$language."\n".$body."\n".$marker;
    }
}
