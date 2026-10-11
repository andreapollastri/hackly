<?php

namespace App\Http\Controllers;

use App\Domain\Compliance\ComplianceFramework;
use App\Domain\Compliance\ComplianceReportExporter;
use App\Domain\Compliance\ComplianceSubject;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplianceReportController extends Controller
{
    public function framework(Request $request, string $framework, string $format, ComplianceReportExporter $exporter): StreamedResponse
    {
        $resolved = ComplianceFramework::tryFrom($framework);
        abort_unless($resolved, 404);

        $format = ComplianceReportExporter::normalizeFormat($format);
        abort_unless(in_array($format, ['pdf', 'markdown'], true), 404);

        return $exporter->framework($resolved, $format, $this->subject($request));
    }

    public function posture(Request $request, string $format, ComplianceReportExporter $exporter): StreamedResponse
    {
        $format = ComplianceReportExporter::normalizeFormat($format);
        abort_unless(in_array($format, ['pdf', 'markdown'], true), 404);

        return $exporter->posture($format, $this->subject($request));
    }

    private function subject(Request $request): ComplianceSubject
    {
        try {
            return ComplianceSubject::fromKey($request->query('subject'));
        } catch (ModelNotFoundException|InvalidArgumentException) {
            abort(404);
        }
    }
}
