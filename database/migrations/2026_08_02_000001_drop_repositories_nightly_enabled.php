<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('repositories', 'nightly_enabled')) {
            return;
        }

        Schema::table('repositories', function (Blueprint $table) {
            $table->dropIndex(['nightly_enabled', 'status']);
            $table->dropColumn('nightly_enabled');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('repositories', 'nightly_enabled')) {
            return;
        }

        Schema::table('repositories', function (Blueprint $table) {
            $table->boolean('nightly_enabled')->default(true)->after('is_private');
            $table->index(['nightly_enabled', 'status']);
        });
    }
};
