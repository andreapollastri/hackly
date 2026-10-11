<?php

use App\Http\Controllers\Api\DocsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ComplianceController;
use App\Http\Controllers\Api\V1\FindingController;
use App\Http\Controllers\Api\V1\GithubCredentialController;
use App\Http\Controllers\Api\V1\PenetrationTestController;
use App\Http\Controllers\Api\V1\RepoScanController;
use App\Http\Controllers\Api\V1\RepositoryController;
use App\Http\Controllers\Api\V1\ScanController;
use App\Http\Controllers\Api\V1\SystemController;
use App\Http\Controllers\Api\V1\TargetController;
use Illuminate\Support\Facades\Route;

Route::get('/docs', [DocsController::class, 'ui'])->name('api.docs');
Route::get('/openapi.yaml', [DocsController::class, 'spec'])->name('api.openapi');

Route::prefix('v1')->middleware(['auth:sanctum', 'ability:*,read,write'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/posture', [SystemController::class, 'posture']);
    Route::get('/profiles', [SystemController::class, 'profiles']);
    Route::get('/scanner-health', [SystemController::class, 'scannerHealth']);
    Route::get('/gates/ci', [SystemController::class, 'ciGate']);
    Route::get('/digest', [SystemController::class, 'digest']);

    Route::get('/compliance', [ComplianceController::class, 'index']);
    Route::get('/compliance/{framework}', [ComplianceController::class, 'show']);
    Route::get('/compliance/{framework}/report/{format}', [ComplianceController::class, 'report']);
    Route::get('/reports/posture', [ComplianceController::class, 'postureDocument']);
    Route::get('/reports/posture/{format}', [ComplianceController::class, 'postureReport']);

    Route::get('/targets', [TargetController::class, 'index']);
    Route::get('/targets/{target}', [TargetController::class, 'show']);
    Route::get('/repositories', [RepositoryController::class, 'index']);
    Route::get('/repositories/{repository}', [RepositoryController::class, 'show']);
    Route::get('/github-credentials', [GithubCredentialController::class, 'index']);
    Route::get('/github-credentials/{credential}', [GithubCredentialController::class, 'show']);
    Route::get('/scans', [ScanController::class, 'index']);
    Route::get('/scans/{scan}', [ScanController::class, 'show']);
    Route::get('/scans/{scan}/report/{format}', [ScanController::class, 'report']);
    Route::get('/repo-scans', [RepoScanController::class, 'index']);
    Route::get('/repo-scans/{repoScan}', [RepoScanController::class, 'show']);
    Route::get('/repo-scans/{repoScan}/report/{format}', [RepoScanController::class, 'report']);
    Route::get('/penetration-tests', [PenetrationTestController::class, 'index']);
    Route::get('/penetration-tests/{pentest}', [PenetrationTestController::class, 'show']);
    Route::get('/findings', [FindingController::class, 'index']);
    Route::get('/findings/{finding}', [FindingController::class, 'show']);
    Route::get('/findings/{finding}/markdown', [FindingController::class, 'markdown']);
    Route::get('/findings/{finding}/prompt', [FindingController::class, 'prompt']);

    Route::middleware('ability:*,write')->group(function () {
        Route::post('/scanner-health/recheck', [SystemController::class, 'recheckScannerHealth']);

        Route::post('/targets', [TargetController::class, 'store']);
        Route::patch('/targets/{target}', [TargetController::class, 'update']);
        Route::delete('/targets/{target}', [TargetController::class, 'destroy']);
        Route::post('/targets/{target}/pause', [TargetController::class, 'pause']);
        Route::post('/targets/{target}/resume', [TargetController::class, 'resume']);
        Route::post('/targets/{target}/verification', [TargetController::class, 'issueVerification']);
        Route::post('/targets/{target}/verification/check', [TargetController::class, 'checkVerification']);
        Route::post('/targets/{target}/verification/regenerate', [TargetController::class, 'regenerateVerification']);
        Route::put('/targets/{target}/repositories', [TargetController::class, 'syncRepositories']);
        Route::post('/targets/{target}/scans', [TargetController::class, 'scan']);

        Route::post('/repositories', [RepositoryController::class, 'store']);
        Route::patch('/repositories/{repository}', [RepositoryController::class, 'update']);
        Route::delete('/repositories/{repository}', [RepositoryController::class, 'destroy']);
        Route::post('/repositories/{repository}/pause', [RepositoryController::class, 'pause']);
        Route::post('/repositories/{repository}/resume', [RepositoryController::class, 'resume']);
        Route::post('/repositories/{repository}/refresh', [RepositoryController::class, 'refresh']);
        Route::put('/repositories/{repository}/targets', [RepositoryController::class, 'syncTargets']);
        Route::post('/repositories/{repository}/scans', [RepositoryController::class, 'scan']);

        Route::post('/github-credentials', [GithubCredentialController::class, 'store']);
        Route::patch('/github-credentials/{credential}', [GithubCredentialController::class, 'update']);
        Route::delete('/github-credentials/{credential}', [GithubCredentialController::class, 'destroy']);
        Route::post('/github-credentials/{credential}/validate', [GithubCredentialController::class, 'validateToken']);

        Route::post('/penetration-tests', [PenetrationTestController::class, 'store']);
        Route::patch('/penetration-tests/{pentest}', [PenetrationTestController::class, 'update']);
        Route::delete('/penetration-tests/{pentest}', [PenetrationTestController::class, 'destroy']);
        Route::post('/penetration-tests/{pentest}/authorization', [PenetrationTestController::class, 'issue']);
        Route::post('/penetration-tests/{pentest}/authorization/check', [PenetrationTestController::class, 'check']);
        Route::post('/penetration-tests/{pentest}/run', [PenetrationTestController::class, 'run']);

        Route::post('/scans/{scan}/cancel', [ScanController::class, 'cancel']);
        Route::post('/scans/{scan}/rerun', [ScanController::class, 'rerun']);
        Route::post('/repo-scans/{repoScan}/cancel', [RepoScanController::class, 'cancel']);
        Route::post('/repo-scans/{repoScan}/rerun', [RepoScanController::class, 'rerun']);

        Route::patch('/findings/{finding}', [FindingController::class, 'update']);
        Route::post('/findings/triage', [FindingController::class, 'triage']);
        Route::post('/findings/{finding}/share', [FindingController::class, 'share']);
        Route::delete('/findings/{finding}/share', [FindingController::class, 'unshare']);
    });

    Route::middleware('abilities:*')->group(function () {
        Route::get('/tokens', [AuthController::class, 'tokens']);
        Route::post('/tokens', [AuthController::class, 'storeToken']);
        Route::delete('/tokens/{token}', [AuthController::class, 'destroyToken'])->whereNumber('token');
    });
});
