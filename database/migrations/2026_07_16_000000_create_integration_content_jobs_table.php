<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_content_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('source_system', 100);
            $table->string('content_package_id', 120);
            $table->string('content_version', 80);
            $table->string('content_digest', 64);
            $table->string('status', 32)->default('received');
            $table->string('locale', 32)->default('zh-CN');
            $table->json('request_payload');
            $table->json('target_sites');
            $table->json('evidence_refs')->nullable();
            $table->json('asset_refs')->nullable();
            $table->string('callback_url', 1000)->nullable();
            $table->text('callback_secret')->nullable();
            $table->string('callback_status', 32)->nullable();
            $table->text('callback_error')->nullable();
            $table->timestamp('callback_attempted_at')->nullable();
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->unsignedBigInteger('created_by_token_id')->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->json('result_payload')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['source_system', 'content_package_id', 'content_version'], 'integration_content_job_source_version_unique');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_content_jobs');
    }
};
