<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('objective_coverage_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_analysis_id')->constrained('curriculum_analyses')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('queued');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('objective_coverage_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_objective_id')->unique()->constrained('learning_objectives')->cascadeOnDelete();
            $table->string('status', 24)->default('unchecked');
            $table->text('reason')->nullable();
            $table->json('supported_focus')->nullable();
            $table->json('missing_focus')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('objective_coverage_checks');
        Schema::dropIfExists('objective_coverage_batches');
    }
};
