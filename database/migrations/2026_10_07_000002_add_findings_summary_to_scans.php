<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Findings are re-attached to the newest scan when re-detected, so each scan keeps
     * a snapshot of its own severity counts taken when it finishes.
     */
    public function up(): void
    {
        foreach (['scans', 'repo_scans'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->json('findings_summary')->nullable()->after('error_message');
            });
        }
    }

    public function down(): void
    {
        foreach (['scans', 'repo_scans'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('findings_summary');
            });
        }
    }
};
