<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocsController extends Controller
{
    public function ui(): View
    {
        return view('api.docs');
    }

    public function spec(): BinaryFileResponse
    {
        return response()->file(base_path('docs/openapi.yaml'), [
            'Content-Type' => 'application/yaml; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="openapi.yaml"',
        ]);
    }
}
