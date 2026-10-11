<?php

namespace App\Http\Controllers;

use App\Domain\Compliance\ComplianceFramework;
use App\Domain\Compliance\ComplianceReportExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplianceReportController extends Controller
{
    public function framework(string $framework, string $format, ComplianceReportExporter $exporter): StreamedResponse
    {
        $resolved = ComplianceFramework::tryFrom($framework);
        abort_unless($resolved, 404);

        $format = ComplianceReportExporter::normalizeFormat($format);
        abort_unless(in_array($format, ['pdf', 'markdown'], true), 404);

        return $exporter->framework($resolved, $format);
    }

    public function posture(string $format, ComplianceReportExporter $exporter): StreamedResponse
    {
        $format = ComplianceReportExporter::normalizeFormat($format);
        abort_unless(in_array($format, ['pdf', 'markdown'], true), 404);

        return $exporter->posture($format);
    }
}
