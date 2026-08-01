<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite: rebuild findings to allow nullable asset_id + global fingerprint uniqueness.
            Schema::table('findings', function (Blueprint $table) {
                $table->dropUnique(['asset_id', 'fingerprint']);
            });

            Schema::table('findings', function (Blueprint $table) {
                $table->uuid('asset_id')->nullable()->change();
            });

            Schema::table('findings', function (Blueprint $table) {
                $table->unique('fingerprint');
            });

            return;
        }

        Schema::table('findings', function (Blueprint $table) {
            $table->dropForeign(['asset_id']);
            $table->dropUnique(['asset_id', 'fingerprint']);
        });

        Schema::table('findings', function (Blueprint $table) {
            $table->uuid('asset_id')->nullable()->change();
        });

        Schema::table('findings', function (Blueprint $table) {
            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->unique('fingerprint');
        });
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        Schema::table('findings', function (Blueprint $table) {
            $table->dropUnique(['fingerprint']);
        });

        if ($driver === 'sqlite') {
            Schema::table('findings', function (Blueprint $table) {
                $table->uuid('asset_id')->nullable(false)->change();
                $table->unique(['asset_id', 'fingerprint']);
            });

            return;
        }

        // Drop orphaned repo-only findings before restoring NOT NULL.
        DB::table('findings')->whereNull('asset_id')->delete();

        Schema::table('findings', function (Blueprint $table) {
            $table->dropForeign(['asset_id']);
        });

        Schema::table('findings', function (Blueprint $table) {
            $table->uuid('asset_id')->nullable(false)->change();
        });

        Schema::table('findings', function (Blueprint $table) {
            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->unique(['asset_id', 'fingerprint']);
        });
    }
};
