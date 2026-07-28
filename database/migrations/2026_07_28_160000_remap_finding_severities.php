<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('findings')->where('severity', 'info')->update(['severity' => 'low']);
        DB::table('findings')->where('severity', 'critical')->update(['severity' => 'high']);
    }

    public function down(): void
    {
        // Irreversible remap of legacy severities.
    }
};
