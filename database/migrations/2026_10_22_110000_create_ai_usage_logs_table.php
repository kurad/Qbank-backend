<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();

            $table->string('provider', 50);
            $table->string('model', 150)->nullable();
            $table->string('operation', 40)->default('chat');
            $table->string('feature', 120)->nullable();
            $table->string('route', 190)->nullable();

            $table->string('status', 20)->default('success');
            $table->boolean('is_fallback')->default(false);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('finish_reason', 80)->nullable();

            $table->unsignedBigInteger('prompt_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('thinking_tokens')->default(0);
            $table->unsignedBigInteger('cached_tokens')->default(0);
            $table->unsignedBigInteger('total_tokens')->default(0);

            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'provider']);
            $table->index(['created_at', 'status']);
            $table->index(['school_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['model', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
