<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_material_supporting_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_material_id')
                ->constrained('course_materials')
                ->cascadeOnDelete();
            $table->foreignId('teacher_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('title');
            $table->text('url');
            $table->string('domain')->nullable();
            $table->string('status', 30)->default('ready');

            $table->string('cached_title')->nullable();
            $table->longText('cached_content')->nullable();
            $table->string('content_type', 120)->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(
                ['course_material_id', 'status'],
                'cm_support_sources_material_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_material_supporting_sources');
    }
};
