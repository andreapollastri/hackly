<?php

namespace App\Domain\Compliance;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Models\Finding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplianceReportExporter
{
    public function __construct(
        private readonly ComplianceReportBuilder $builder,
        private readonly PostureScore $posture,
    ) {}

    public function framework(ComplianceFramework $framework, string $format): StreamedResponse
    {
        $report = $this->builder->build($framework);
        $slug = $framework->value;

        if ($format === 'pdf') {
            return $this->pdf('reports.compliance', ['report' => $report], "hackly-{$slug}");
        }

        return $this->markdown('reports.compliance-md', ['report' => $report], "hackly-{$slug}");
    }

    public function posture(string $format): StreamedResponse
    {
        $report = $this->postureDocument();

        if ($format === 'pdf') {
            return $this->pdf('reports.posture', ['report' => $report], 'hackly-posture');
        }

        return $this->markdown('reports.posture-md', ['report' => $report], 'hackly-posture');
    }

    /**
     * @return array<string, mixed>
     */
    public function postureDocument(): array
    {
        $findings = Finding::query()
            ->issues()
            ->open()
            ->with(['asset:id,value', 'repository:id,full_name'])
            ->orderByRaw(FindingSeverity::orderByRankSql().' desc')
            ->limit(15)
            ->get();

        return [
            'disclaimer' => ComplianceReportBuilder::DISCLAIMER,
            'generated_at' => now()->toIso8601String(),
            'posture' => $this->posture->snapshot(),
            'findings' => $findings->map(fn (Finding $finding): array => [
                'title' => $finding->title,
                'severity' => $finding->severity instanceof FindingSeverity ? $finding->severity->value : (string) $finding->severity,
                'status' => $finding->status instanceof FindingStatus ? $finding->status->value : (string) $finding->status,
                'category' => $finding->category,
                'subject' => $finding->subjectName(),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function pdf(string $view, array $data, string $basename): StreamedResponse
    {
        $pdf = Pdf::loadView($view, $data)->setPaper('a4');
        $filename = $basename.'-'.now()->format('Y-m-d').'.pdf';

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function markdown(string $view, array $data, string $basename): StreamedResponse
    {
        $markdown = view($view, $data)->render();
        $filename = $basename.'-'.now()->format('Y-m-d').'.md';

        return response()->streamDownload(
            function () use ($markdown): void {
                echo $markdown;
            },
            $filename,
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    }

    public static function normalizeFormat(string $format): string
    {
        $format = Str::lower($format);

        return match ($format) {
            'pdf' => 'pdf',
            'md', 'markdown' => 'markdown',
            default => $format,
        };
    }
}
