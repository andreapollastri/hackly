<?php

use App\Domain\Scanning\Support\NucleiRuntime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Ensure Nuclei has a writable HOME under storage (config/cache/templates).
     * Best-effort chown to the web/queue user when possible (e.g. www-data).
     */
    public function up(): void
    {
        $result = NucleiRuntime::provision();

        Log::info('hackly.nuclei.home.provisioned', $result);

        // Safe after deploy/migrate: drop stale config/route/view caches.
        try {
            Artisan::call('optimize:clear');
        } catch (\Throwable) {
            // Ignore when running outside a full app bootstrap context.
        }
    }

    public function down(): void
    {
        // Keep nuclei-home contents (templates/config); nothing to reverse.
    }
};
