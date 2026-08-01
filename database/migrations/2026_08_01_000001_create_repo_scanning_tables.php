<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('token');
            $table->string('token_hint')->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->string('validation_status')->default('unknown');
            $table->json('meta')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('repositories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('github_credential_id')->constrained('github_credentials')->cascadeOnDelete();
            $table->string('owner');
            $table->string('name');
            $table->string('full_name')->unique();
            $table->string('default_branch')->default('main');
            $table->boolean('is_private')->default(true);
            $table->boolean('nightly_enabled')->default(true);
            $table->string('status')->default('active');
            $table->string('html_url')->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->string('last_commit_sha')->nullable();
            $table->json('meta')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['nightly_enabled', 'status']);
        });

        Schema::create('asset_repository', function (Blueprint $table) {
            $table->foreignUuid('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('repository_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['asset_id', 'repository_id']);
        });

        Schema::create('repo_scans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('repository_id')->constrained()->cascadeOnDelete();
            $table->string('profile')->default('standard');
            $table->string('status')->default('pending');
            $table->string('commit_sha')->nullable();
            $table->string('workspace_path')->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['repository_id', 'status']);
        });

        Schema::create('repo_scan_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('repo_scan_id')->constrained('repo_scans')->cascadeOnDelete();
            $table->string('type');
            $table->string('queue')->default('default');
            $table->string('status')->default('pending');
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
            $table->index(['repo_scan_id', 'type']);
        });

        Schema::table('findings', function (Blueprint $table) {
            $table->foreignUuid('repository_id')->nullable()->after('asset_id')->constrained()->nullOnDelete();
            $table->foreignUuid('repo_scan_id')->nullable()->after('scan_id')->constrained('repo_scans')->nullOnDelete();
            $table->foreignUuid('repo_scan_task_id')->nullable()->after('scan_task_id')->constrained('repo_scan_tasks')->nullOnDelete();
            $table->string('reachability')->nullable()->after('status');
            $table->boolean('noise_filtered')->default(false)->after('reachability');
            $table->unsignedTinyInteger('confidence')->nullable()->after('noise_filtered');
            $table->index(['repository_id', 'status', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            $table->dropIndex(['repository_id', 'status', 'severity']);
            $table->dropConstrainedForeignId('repo_scan_task_id');
            $table->dropConstrainedForeignId('repo_scan_id');
            $table->dropConstrainedForeignId('repository_id');
            $table->dropColumn(['reachability', 'noise_filtered', 'confidence']);
        });

        Schema::dropIfExists('repo_scan_tasks');
        Schema::dropIfExists('repo_scans');
        Schema::dropIfExists('asset_repository');
        Schema::dropIfExists('repositories');
        Schema::dropIfExists('github_credentials');
    }
};
