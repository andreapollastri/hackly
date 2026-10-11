<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Compliance\ComplianceFramework;
use App\Domain\Compliance\ComplianceReportBuilder;
use App\Domain\Compliance\ComplianceReportExporter;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplianceController extends ApiController
{
    public function index(ComplianceReportBuilder $builder): JsonResponse
    {
        return response()->json(['data' => $builder->summaries()]);
    }

    public function show(string $framework, ComplianceReportBuilder $builder): JsonResponse
    {
        return response()->json(['data' => $builder->build($this->framework($framework))]);
    }

    public function report(string $framework, string $format, ComplianceReportExporter $exporter): StreamedResponse
    {
        return $exporter->framework($this->framework($framework), $this->format($format));
    }

    public function postureReport(string $format, ComplianceReportExporter $exporter): StreamedResponse
    {
        return $exporter->posture($this->format($format));
    }

    public function postureDocument(ComplianceReportExporter $exporter): JsonResponse
    {
        return response()->json(['data' => $exporter->postureDocument()]);
    }

    private function framework(string $framework): ComplianceFramework
    {
        return ComplianceFramework::tryFrom($framework) ?? $this->fail('Unknown compliance framework.', 404);
    }

    private function format(string $format): string
    {
        $format = ComplianceReportExporter::normalizeFormat($format);

        if (! in_array($format, ['pdf', 'markdown'], true)) {
            $this->fail('Format must be pdf or markdown.', 404);
        }

        return $format;
    }
}
