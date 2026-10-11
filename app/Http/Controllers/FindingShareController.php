<?php

namespace App\Http\Controllers;

use App\Domain\Findings\FindingBrief;
use App\Models\Finding;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FindingShareController extends Controller
{
    public function __invoke(Request $request, string $hash): View|Response
    {
        $finding = Finding::query()->where('publish_hash', $hash)->firstOrFail();
        $finding->loadMissing(['asset:id,value', 'repository:id,full_name']);

        $format = (string) $request->query('format', '');
        $headers = [
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'private, no-store',
        ];

        if (in_array($format, ['markdown', 'md'], true)) {
            return response(FindingBrief::markdown($finding, public: true), 200, $headers + [
                'Content-Type' => 'text/markdown; charset=UTF-8',
            ]);
        }

        if ($format === 'prompt') {
            return response(FindingBrief::prompt($finding, public: true), 200, $headers + [
                'Content-Type' => 'text/markdown; charset=UTF-8',
            ]);
        }

        return response()
            ->view('public.finding', [
                'finding' => $finding,
                'markdown' => FindingBrief::markdown($finding, public: true),
                'prompt' => FindingBrief::prompt($finding, public: true),
            ])
            ->withHeaders($headers);
    }
}
