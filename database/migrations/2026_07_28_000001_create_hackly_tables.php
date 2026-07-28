<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // domain|ip
            $table->string('value')->unique();
            $table->string('status')->default('active'); // active|paused|archived
            $table->text('authorization_note')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_token')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('scan_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('per_target_per_minute')->default(2);
            $table->unsignedInteger('global_concurrent')->default(5);
            $table->unsignedInteger('jitter_seconds')->default(45);
            $table->unsignedInteger('task_spacing_seconds')->default(30);
            $table->unsignedInteger('deep_cooldown_hours')->default(24);
            $table->boolean('quiet_hours_enabled')->default(false);
            $table->unsignedTinyInteger('quiet_hours_start')->default(0);
            $table->unsignedTinyInteger('quiet_hours_end')->default(6);
            $table->string('timezone')->default('UTC');
            $table->timestamps();
        });

        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('profile'); // quick|standard|deep
            $table->string('status')->default('pending'); // pending|running|completed|failed|cancelled
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'status']);
        });

        Schema::create('scan_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
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
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scan_task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('severity'); // info|low|medium|high|critical
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
            $table->id();
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
        Schema::dropIfExists('scan_policies');
        Schema::dropIfExists('assets');
    }
};
