<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            $table->text('false_positive_note')->nullable()->after('status');
            $table->timestamp('false_positive_noted_at')->nullable()->after('false_positive_note');
            $table->foreignUuid('false_positive_noted_by')->nullable()->after('false_positive_noted_at')->constrained('users')->nullOnDelete();
            $table->string('publish_hash', 64)->nullable()->unique()->after('false_positive_noted_by');
            $table->timestamp('published_at')->nullable()->after('publish_hash');
            $table->foreignUuid('published_by')->nullable()->after('published_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_by');
            $table->dropConstrainedForeignId('false_positive_noted_by');
            $table->dropColumn(['false_positive_note', 'false_positive_noted_at', 'publish_hash', 'published_at']);
        });
    }
};
