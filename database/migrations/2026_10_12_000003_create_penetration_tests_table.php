<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penetration_tests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('label');
            $table->string('technology');
            $table->text('technology_notes')->nullable();
            $table->string('swagger_url')->nullable();
            $table->json('login_urls')->nullable();
            $table->json('extra_urls')->nullable();
            $table->json('ports')->nullable();
            $table->text('notes')->nullable();
            $table->text('authentication_notes')->nullable();
            $table->text('out_of_scope')->nullable();
            $table->string('test_username')->nullable();
            $table->text('test_password')->nullable();
            $table->string('authorization_token', 80)->nullable();
            $table->timestamp('authorization_verified_at')->nullable();
            $table->timestamp('authorization_consumed_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['asset_id', 'created_at']);
        });

        Schema::table('scans', function (Blueprint $table) {
            $table->foreignUuid('penetration_test_id')
                ->nullable()
                ->after('asset_id')
                ->constrained('penetration_tests')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('penetration_test_id');
        });

        Schema::dropIfExists('penetration_tests');
    }
};
