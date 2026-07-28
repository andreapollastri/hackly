<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type'); // domain|ip
            $table->string('value')->unique();
            $table->string('status')->default('active'); // active|paused|archived
            $table->text('authorization_note')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_token')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('scans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('asset_id')->constrained()->cascadeOnDelete();
            $table->string('profile'); // quick|standard|deep
            $table->string('status')->default('pending'); // pending|running|completed|failed|cancelled
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'status']);
        });

        Schema::create('scan_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('scan_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('queue')->default('default');
            $table->string('status')->default('pending'); // pending|queued|running|completed|failed|skipped
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('raw_output_path')->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index(['scan_id', 'type']);
        });

        Schema::create('findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('scan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('scan_task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('severity'); // low|medium|high
            $table->string('title');
            $table->string('category')->nullable();
            $table->string('cve')->nullable();
            $table->string('source'); // nmap|nuclei|zap|dns|http
            $table->string('status')->default('open'); // open|ack|fixed|false_positive
            $table->string('fingerprint')->nullable();
            $table->json('evidence')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'status', 'severity']);
            $table->unique(['asset_id', 'fingerprint']);
        });

        Schema::create('rate_limit_buckets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('target_key')->unique();
            $table->unsignedInteger('tokens_used')->default(0);
            $table->timestamp('window_starts_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_limit_buckets');
        Schema::dropIfExists('findings');
        Schema::dropIfExists('scan_tasks');
        Schema::dropIfExists('scans');
        Schema::dropIfExists('assets');
    }
};
