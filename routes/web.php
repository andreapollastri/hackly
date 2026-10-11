<?php

use App\Http\Controllers\ComplianceReportController;
use App\Http\Controllers\FindingShareController;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

Route::get('/f/{hash}', FindingShareController::class)
    ->where('hash', '[A-Za-z0-9]{40}')
    ->middleware('throttle:60,1')
    ->name('findings.public');

Route::middleware(Authenticate::class)->group(function () {
    Route::get('/compliance-reports/posture/{format}', [ComplianceReportController::class, 'posture'])
        ->name('compliance.posture');
    Route::get('/compliance-reports/{framework}/{format}', [ComplianceReportController::class, 'framework'])
        ->name('compliance.report');
});
