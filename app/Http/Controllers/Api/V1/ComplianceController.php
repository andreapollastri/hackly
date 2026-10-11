<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Compliance\ComplianceFramework;
use App\Domain\Compliance\ComplianceReportBuilder;
use App\Domain\Compliance\ComplianceReportExporter;
use App\Domain\Compliance\ComplianceSubject;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplianceController extends ApiController
{
    public function index(Request $request, ComplianceReportBuilder $builder): JsonResponse
    {
        return response()->json(['data' => $builder->summaries(subject: $this->subject($request))]);
    }

    public function show(Request $request, string $framework, ComplianceReportBuilder $builder): JsonResponse
    {
        return response()->json(['data' => $builder->build($this->framework($framework), subject: $this->subject($request))]);
    }

    public function report(Request $request, string $framework, string $format, ComplianceReportExporter $exporter): StreamedResponse
    {
        return $exporter->framework($this->framework($framework), $this->format($format), $this->subject($request));
    }

    public function postureReport(Request $request, string $format, ComplianceReportExporter $exporter): StreamedResponse
    {
        return $exporter->posture($this->format($format), $this->subject($request));
    }

    public function postureDocument(Request $request, ComplianceReportExporter $exporter): JsonResponse
    {
        return response()->json(['data' => $exporter->postureDocument($this->subject($request))]);
    }

    private function subject(Request $request): ComplianceSubject
    {
        try {
            return ComplianceSubject::fromKey($request->query('subject'));
        } catch (ModelNotFoundException|InvalidArgumentException) {
            $this->fail('Unknown subject. Use a target, a repository, or a linked pair.', 404);
        }
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
